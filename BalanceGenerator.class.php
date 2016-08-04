<?php

/**
 * @author Ryan Pierce
 * V0.1, July 2016
 * Developed for PHP 5.5
 *
 * A class for tabulating the liability balance for an individual account
 *
 * $balance = new BalanceGenerator($allRuleSets)
 * @param array $allRuleSets
 *
 *    An array of multiple sets of universal company rules is required during construction. These dictate policy
 *    for when an escalation occurs, how overdue fees are computed, etc.  The sets of rules are date based
 *    and a particular rule set applies when the date of any given credit is in the rule set's date range.
 *    This can also be input as an array or json.
 *
 *    Example array structure:  $allRuleSets['2015-10-23']['overdue_percentage']
 *
 * $balance->validate()
 *    Returns false if ruleSets are invalid
 *
 * $balance->getBalance($accountHistory)
 *    Data input is an array of debit and credit events EXCLUDING accrued overdue fees and can be in an
 *    array or json.
 *
 *    Example array structure: $accountHistory[3]['date']
 *
 *    Overdue is generated INSIDE this class so it is important not to feed a overdue to the class
 *
 *    Output is not only a summary of net due, overdue, and overdue fees - also included are a timeline of changes
 *    to any net (or actions), recommended level of overdue escalation (or refund), and any warnings regarding the
 *    input data (necessary to catch anything missed)
 *
 * This class is currency blind and does not take the currency used into account
 *
 * THIS CODE IS FOR EDUCATIONAL PURPOSES ONLY AND DOES NOT NECESSARILY CONFORM TO GAAP OR ANY OTHER FINANCIAL REGULATIONS
 */
class BalanceGenerator
{

    /**
     * These are the required fields in a single set of rules
     * The keys for this array are the key names required, the values are a regex validator
     *
     * @var array
     *
     */
    public $requiredRuleFields = [
        'overduePercentage' => '^(?:\d+|\d*\.\d+)$', // Percentage of the overdue credit in late charges (.015 is 1.5%)
        'minOverdueCharge' => '^(?:\d+|\d*\.\d+)$', // Minimum amount of currency to fee if fee is otherwise smaller
        'daysOverdueForRep' => '^[\d]+$', // Number of days before an internal collections rep should be flagged
        // The second highest level of escalation
        'daysOverdueForAgency' => '^[\d]+$', // Number of days before this is sent to an external collections agency
        // This is the highest level of escalation
    ];

    /**
     * These are the required fields in a single accountEvent
     * The keys for this array are the key names required, the values are a regex validator
     *
     * @var array
     *
     */
    public $requiredCreditDebitFields = [
        'date' => '^[\d]{4}-[\d]{2}-[\d]{2}$', // The date of the action
        'amount' => '^-?(?:\d+|\d*\.\d+)$', // Positive is a credit (adds to amount owed), negative is a debit
        'description' => '', // A text description of action
        'notes' => '', // Internal notes on the transaction
        'netD' => '^[\d]+$', // Days after transaction that a full payment is required, or is overdue (999 is no overdue)
    ];

    /**
     * BalanceGenerator constructor.
     * @param $allRuleSets
     *      A ruleSet's key name must be the date that it first comes into play
     *      An example is $allRuleSets['2015-10-23']['overdue_percentage']
     *      Requirements are in BalanceGenerator->requiredRuleFields
     *      Can be entered either as an array or as a json string
     */
    public function __construct($allRuleSets)
    {
        $this->allRuleSets = $this->returnArrayEvenFromJson($allRuleSets);
        $this->rulesAreValid = $this->validateRules();
    }

    /**
     * @param $accountHistory
     *      Input is either an array or json of sets of credits and debits.
     *      Uses a sequential array of associative arrays:  $accountHistory[2]['date']
     *      Requirements are in BalanceGenerator->requiredCreditDebitFields
     * @return array
     *      Array contains a timeline of changes to balance history,
     *      current balance,
     *      overdue balance,
     *      escalation flag for determining collection activity required.
     *      Returns an errorMessage field if anything goes wrong.
     */
    public function getBalance($accountHistory)
    {
        $accountHistory = $this->returnArrayEvenFromJson($accountHistory);
        $generatedBalance['errorMessages'] = $this->validateHistory($accountHistory);
        if (strlen($generatedBalance['errorMessages']) < 1 && $this->validate()) {
            // if no errors were generated during validation of history AND ruleSets are valid we can continue
            usort($accountHistory, function ($a, $b) { // Sort the account history in chronological order
                return  strtotime($a['date']) - strtotime($b['date']);
            });
            $generatedBalance = $this->compileBalance(array_reverse($accountHistory));
        }
        elseif (!$this->validate()) {
            $generatedBalance['errorMessages'] = "Invalid ruleSets";
        }
        return ($generatedBalance);
    }

    /**
     *
     * Return true if the instance was created with a valid ruleSet
     *
     * @return bool
     */
    public function validate()
    {
        return ($this->rulesAreValid);
    }

    /**
     * Add an account event to the balanceTimeline
     *
     * @param $accountEvent
     */
    private function appendBalanceTimeline($accountEvent)
    {
        array_push($this->balanceTimeline, $accountEvent);
        usort($this->balanceTimeline, function ($a, $b) { // Sort in chronological order
            return  strtotime($a['date']) - strtotime($b['date']);
        });
    }

    /**
     *
     * If accountEvent is a credit add to unpaidCredits; if debit add to funds instead;
     * With either add to netBalance
     *
     * @param $accountEvent
     * @return mixed
     *
     */
    private function applyCreditOrDebit($accountEvent)
    {
        if ($accountEvent['amount'] > 0) { // Credits are marked as positive amounts
            array_push($this->unpaidCredits, $accountEvent); //if credit, append to unpaid credit array
        }
        else {
            $this->funds = $this->funds - $accountEvent['amount']; // If a debit this actually adds to funds since debit
                                                                   // is indicated with a negative amount
        }
        $this->netBalance = $this->netBalance + $accountEvent['amount'];
    }

    /**
     *
     * Apply spare funds to any unpaid credits,
     * oldest credits first.
     *
     * @param $accountEvent
     */
    private function applySpareFundsToCredit($accountEvent)
    {
        while ($this->funds > 0 && isset($this->unpaidCredits[0]['amount'])) { //while there are funds and unpaidCredits
            if ($this->funds >= $this->unpaidCredits[0]['amount']) { // can totally pay off a credit
                $this->unpaidCredits[0]['description'] =
                    "Credit paid for " . $this->unpaidCredits[0]['date'] . " with remaining " . $this->funds;
                $this->unpaidCredits[0]['date'] = $accountEvent['date'];
                $this->unpaidCredits[0]['notes'] = "Credit payment of ". $this->funds;
                $this->funds = $this->funds - $this->unpaidCredits[0]['amount']; // get remainder of funds
                $this->unpaidCredits[0]['amount'] = 0; // credit is paid off
                // remove credit from unpaid and add event to timeline
                $this->appendBalanceTimeline(array_shift($this->unpaidCredits));
            } else { // can partially pay off a credit
                $this->unpaidCredits[0]['description'] =
                    $this->funds . " put towards credit for " . $this->unpaidCredits[0]['date'];
                $this->unpaidCredits[0]['date'] = $accountEvent['date'];
                $this->unpaidCredits[0]['notes'] = "Credit payment of ". $this->funds;
                $this->unpaidCredits[0]['amount'] = $this->unpaidCredits[0]['amount'] - $this->funds; // partial payoff
                $this->funds = 0; // funds are used up
                // add event to timeline
                $this->appendBalanceTimeline($this->unpaidCredits[0]);
            }
        }
    }

    /**
     *
     * Prepare the balance and compile the results
     *
     * @param $accountHistory
     * @return array
     *      Returns a balance array which will either just be an error field or it will be
     *      the intended results of the BalanceGenerator
     */
    private function compileBalance($accountHistory)
    {
        $this->accountHistory = $accountHistory;
        $generatedBalance = array();
        $generatedBalance['errorMessages'] = '';
        $this->netBalance = 0; // netBalance is sum of debits and credits and fees, debits are neg numbers
        $this->funds = 0; // Funds are money that has been debited that has not yet been put to a credit
        $this->unpaidCredits = []; // Each credit adds an additional row, each debit pays off the oldest existing
        //     unpaid credit first.  When a credit is paid off, we remove from this
        //     array
        $this->balanceTimeline = []; // Each time a new debit / credit / overdue is generated it is appended to this
        foreach ($this->accountHistory as $accountEvent) { // A accountEvent is a credit / debit
            $ruleSet = $this->pickRuleSet($accountEvent['date']); // Pick the ruleSet that applies to this accountEvent
            if (is_array($ruleSet)) {
                $this->appendBalanceTimeline($accountEvent); // All account events go to balance timeline
                // Create a overdue fee event IF the unpaid credit is overdue AND none has been created for it already.
                $this->setOverdue($accountEvent['date'], $ruleSet);
                // Apply accountEvent to netBalance.  If it's a credit append to unpaidCredits
                $this->applyCreditOrDebit($accountEvent);
                // If there are funds to spare, apply those funds to outstanding credits
                $this->applySpareFundsToCredit($accountEvent);
            } else {
                $generatedBalance['errorMessages'] .= "Rule Set not found for " . $accountEvent['date'] . "\n";
                return ($generatedBalance); // Exit before making full balance on any error
            }
        }
        if (strlen($generatedBalance['errorMessages']) < 1) {
            // Since there were no errors we want to compile our balance
            // but we want to first check if anything is overdue as of today
            $ruleSet = $this->pickRuleSet(date("Y-m-d"));
            $this->setOverdue(date("Y-m-d"), $ruleSet);
            if ($this->netBalance < .01 && $this->netBalance > .01) $this->netBalance = 0;
            $this->netBalance = round($this->netBalance, 3);
            // Now that we caught up we can actually compile the balance
            $generatedBalance = array(
                'escalationFlag' => $this->getEscalationFlag($ruleSet),
                'overdueBalance' => $this->getOverdueBalance(),
                'netBalance' => round($this->netBalance, 2),
                'balanceTimeline' => $this->balanceTimeline,
            );
        }
        return ($generatedBalance);
    }

    /**
     *
     * Determine which escalation flag the customer account is at.
     * This is used for classifying past due customers as far as
     * collection intensity.
     *
     * @param $ruleSet
     *      This should be for the ruleSet that applies to the
     *      current date.
     *
     * @return string
     */
    private function getEscalationFlag ($ruleSet) {
        $escalationFlag = "No unpaid credits";
        if (isset($this->unpaidCredits[0]['amount'])) {
            $escalationFlag = "Unpaid credits but none late";
            $dateThisIsDue = $this->getOverdueDate($this->unpaidCredits[0]['date'], $this->unpaidCredits[0]['netD']);
            $daysOverdue = floor((time() - strtotime($dateThisIsDue)) / 86400);
            if (0 <= $daysOverdue) {
                $escalationFlag = "Overdue credit";
            }
            if ($ruleSet['daysOverdueForRep'] <= $daysOverdue) {
                $escalationFlag = "Use internal collection representative";
            }
            if ($ruleSet['daysOverdueForAgency'] <= $daysOverdue) {
                $escalationFlag = "Use external collection agency";
            }
        }
        return ($escalationFlag);
    }

    /**
     *
     * Determine how much net credit is overdue.
     * Please not this does not add in credit that is simply
     * due but not late.
     *
     * @return int
     */
    private function getOverdueBalance () {
        $overdueBalance = 0;
        foreach ($this->unpaidCredits as $unpaidCredit) {
            $dateThisIsDue = $this->getOverdueDate($unpaidCredit['date'], $unpaidCredit['netD']);
            if ($dateThisIsDue <= date("Y-m-d")) {
                $overdueBalance = $overdueBalance + $unpaidCredit['amount'];
            }
        }
        return ($overdueBalance);
    }

    /**
     *
     * Determine how much of a fee will be charged for a past due bill.
     * It will use either the overduePercentage resulting value OR the minOverdueCharge,
     * whichever is greater.
     *
     * @param $amountOverdue
     * @param $ruleSet
     * @return mixed
     */
    private function getOverdueCharge($amountOverdue, $ruleSet)
    {
        $overdueFee = $ruleSet['overduePercentage'] * $amountOverdue;
        if ($overdueFee < $ruleSet['minOverdueCharge']) {
            $overdueFee = $ruleSet['minOverdueCharge'];
        }
        return ($overdueFee);
    }

    private function getOverdueDate($dateOfCharge, $netD) {
        return (date('Y-m-d', strtotime($dateOfCharge . "+" . $netD . " days")));
    }

    /**
     *
     * Find the best ruleSet for any given date, or return null if none
     * qualify.  None qualifying will only occur if there is a transaction
     * from before a date when there are any ruleSets.
     *
     * @param $accountEventDate
     * @return mixed|null
     */
    private function pickRuleSet($accountEventDate)
    {
        $bestRuleSetDate = '';
        foreach ($this->allRuleSets as $ruleSetStartDate => $potentialRuleSet) {
            if ($bestRuleSetDate < $ruleSetStartDate && $ruleSetStartDate <= $accountEventDate) {
                $bestRuleSetDate = $ruleSetStartDate;
            }
        }
        $ruleSet = null;
        if (isset($this->allRuleSets[$bestRuleSetDate])) $ruleSet = $this->allRuleSets[$bestRuleSetDate];
        return ($ruleSet);
    }

    /**
     *
     * If this is passed an array, it returns an array.  If it is passed
     * valid JSON it is returned as an array.  Anything else will not return
     * an array.
     *
     * @param $potentialJson
     * @return array|mixed
     */
    private function returnArrayEvenFromJson($potentialJson)
    {
        if (!is_array($potentialJson)) return(json_decode($potentialJson, true));
        else return ($potentialJson);
    }

    /**
     *
     * Create an overdue fee credit and put it in the unpaidCredits,
     * and also add the event to the balanceTimeline.
     *
     * @param $checkDate
     * @param $ruleSet
     */
    private function setOverdue($checkDate, $ruleSet)
    {
        foreach ($this->unpaidCredits as $unpaidKey => $unpaidCredit) {
            $dateThisIsDue = $this->getOverdueDate($unpaidCredit['date'], $unpaidCredit['netD']);
            if ($dateThisIsDue <= $checkDate && !isset($unpaidCredit['overdueFeeCreated'])) {
                $this->unpaidCredits[$unpaidKey]['overdueFeeCreated'] = true;
                $overdueEvent['overdueFeeCreated'] = true;
                $overdueEvent['date'] = $dateThisIsDue;
                $overdueEvent['notes'] = 'Overdue Fee';
                $overdueEvent['netD'] = 999;
                $overdueEvent['description'] = "Overdue fee for original " . $unpaidCredit['date'] . " credit.";
                $overdueEvent['amount'] = $this->getOverdueCharge($unpaidCredit['amount'], $ruleSet);
                $this->appendBalanceTimeline($overdueEvent);
                array_push($this->unpaidCredits, $overdueEvent);
            }
        }
    }

    /**
     *
     * Return true only if this is a proper date
     *
     * @param $dateToValidate
     * @return bool
     */
    private function validateDate($dateToValidate) {
        $timestamp = strtotime($dateToValidate);
        if ($timestamp > 0) return true;
        else false;
    }

    /**
     *
     * Return true if there is at least one ruleSet and all
     * the required fields are used in all the ruleSets.
     *
     * @return bool
     */
    private function validateRules()
    {
        // Every ruleSet will need a start date association
        // There MUST be at least one ruleSet
        // Returns true if validates
        $noInvalidRulesFound = true;
        $containsAtLeastOneSet = false;
        if (is_array($this->allRuleSets)) { // Rules must be in an array
            foreach ($this->allRuleSets as $ruleSetStartDate => $ruleSet) { // Cycle through ruleSets, key should be a date
                if (preg_match('/^[\d]{4}-[\d]{2}-[\d]{2}$/', $ruleSetStartDate)) { // Only ruleSets with a date as a key count
                    $containsAtLeastOneSet = true;
                    if (!$this->validateRuleSet($ruleSet)) { // Check if not all required fields exist and have proper data
                        $noInvalidRulesFound = false;
                    }
                }
            }
        }
        if ($noInvalidRulesFound && $containsAtLeastOneSet) return true;
        else return false;
    }

    /**
     *
     * Check each field in a given ruleSet for requirement matching,
     * if even one field is wrong return false.
     *
     * @param $ruleSet
     * @return bool
     */
    private function validateRuleSet($ruleSet)
    {
        // Every required field for the ruleSet MUST exist AND must pass a ruleRegex if one exists
        $noInvalidRulesFound = true;
        foreach ($this->requiredRuleFields as $ruleName => $ruleRegex) {
            if (isset($ruleSet[$ruleName])) { // first requirement is the required field actually exists
                if (strlen($ruleRegex) > 0) { // if there is a regex for that field lets check it, skip if blank
                    if (!preg_match("/$ruleRegex/", $ruleSet[$ruleName])) {
                        $noInvalidRulesFound = false; // if the regex did NOT work
                    }
                }
            } else {
                $noInvalidRulesFound = false; // if the required field does NOT exist
            }
        }
        return ($noInvalidRulesFound);
    }


    /**
     *
     * Validate every accountEvent in the accountHistory.
     * If any accountEvent is missing a required field or a
     * field has an improper value that is an error.
     *
     * Data errors are treated strictly so that an operator
     * can find any typos which can be a serious issue in
     * regards to finances.
     *
     * @param $accountHistory
     * @return string
     */
    private function validateHistory($accountHistory)
    {
        // Every history event will need all the required fields and to pass the required rulesRegex
        // There is NOT a requirement for any history events to exist, however
        // Returns an error string if any part fails to validate
        $errorMessages = '';
        foreach ($accountHistory as $accountEvent) {
            if (!$this->validateDate($accountEvent['date'])) {
                $errorMessages .= "Invalid accountEvent Field Data: ". $accountEvent['date'] ." is not a date!\n";
            }
            foreach ($this->requiredCreditDebitFields as $accountEventFieldName => $eventFieldRegex) {
                if (isset($accountEvent[$accountEventFieldName])) { // first requirement is the required field actually exists
                    if (strlen($eventFieldRegex) > 0) { // if there is a regex for that field lets check it, skip if blank
                        if (!preg_match("/$eventFieldRegex/", $accountEvent[$accountEventFieldName])) {
                            $errorMessages .= "Invalid accountEvent Field Data: Field $accountEventFieldName for " . $accountEvent['date'] . "\n"; // if the regex did NOT work
                        }
                    }
                } else {
                    $errorMessages .= "Missing Field: Field $accountEventFieldName from " . $accountEvent['date'] . "\n"; // if the required field does NOT exist
                }
            }
        }
        return ($errorMessages);
    }



}

