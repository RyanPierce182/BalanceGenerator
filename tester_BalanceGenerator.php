<?php
/**
 * Created by PhpStorm.
 * User: Ryan Pierce
 * Date: 7/26/16
 *
 * A unit tester for the BalanceGenerator class
 */

include("BalanceGenerator.class.php");

$resultDir = "testresults/";

$AllRuleSetJSON = array();
$ruleSetDir = "rulesets/";
foreach (scandir($ruleSetDir) as $potentialRuleSetFile) {
    if (preg_match("/^ruleset_.+\.json/i", $potentialRuleSetFile)) {
        $AllRuleSetJSON[$potentialRuleSetFile] = file_get_contents($ruleSetDir.$potentialRuleSetFile);
    }
}

$AllTestCustomerJSON = array();
$testCustomerDir = "testdata/";
foreach (scandir($testCustomerDir) as $potentialTestCustomerFile) {
    if (preg_match("/^testCustomer_.+\.json/i", $potentialTestCustomerFile)) {
        $AllTestCustomerJSON[$potentialTestCustomerFile] = file_get_contents($testCustomerDir.$potentialTestCustomerFile);
    }
}


foreach ($AllRuleSetJSON as $originalRuleSetFile => $ruleSetJSON) {
    $balanceGenerator = new BalanceGenerator($ruleSetJSON);
    foreach ($AllTestCustomerJSON as $originalTestCustomerFile => $testCustomerJSON) {
        $balance = $balanceGenerator->getBalance($testCustomerJSON);
        file_put_contents(
            $resultDir."testresult_".preg_replace("/\.json/", "", $originalRuleSetFile)."_$originalTestCustomerFile",
            json_encode($balance, JSON_PRETTY_PRINT)
        );
    }
}

echo "Results across test cases finished, check $resultDir\n";
