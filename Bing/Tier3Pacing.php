<?php

session_start();

// // The BingAds OAuth access token will be in session data. This is the token used
// // to access BingAds data on behalf of the customer.
$accessToken = $_SESSION['access_token'];
$refreshToken = $_SESSION['refresh_token'];

printf("Implement code to retrieve data with access token: %s", $accessToken);
printf("Implement code to retrieve data with access token: %s", $refreshToken);

require_once dirname(__FILE__) . '/../vendor/autoload.php';
require_once dirname(__FILE__) . '/../includes/db_conn.php';
require_once dirname(__FILE__) . '/../includes/T3_reg_campaigns_bing.php';  
require_once dirname(__FILE__) . '/../includes/T3_reg_accounts_bing.php';  

// Include the Bing Ads namespaced class files available
// for download at http://go.microsoft.com/fwlink/?LinkId=322147
include '../vendor/bing/PHP/Bing Ads API in PHP/v9/bingads/ReportingClasses.php';
include '../vendor/bing/PHP/Bing Ads API in PHP/v9/bingads/ClientProxy.php';

// Specify the BingAds\Reporting objects that will be used.
use BingAds\Reporting\SubmitGenerateReportRequest;
use BingAds\Reporting\KeywordPerformanceReportRequest;
use BingAds\Reporting\ReportFormat;
use BingAds\Reporting\ReportAggregation;
use BingAds\Reporting\AccountThroughAdGroupReportScope;
use BingAds\Reporting\CampaignReportScope;
use BingAds\Reporting\ReportTime;
use BingAds\Reporting\ReportTimePeriod;
use BingAds\Reporting\Date;
use BingAds\Reporting\KeywordPerformanceReportFilter;
use BingAds\Reporting\DeviceTypeReportFilter;
use BingAds\Reporting\KeywordPerformanceReportColumn;
use BingAds\Reporting\PollGenerateReportRequest;
use BingAds\Reporting\ReportRequestStatusType;
use BingAds\Reporting\KeywordPerformanceReportSort;
use BingAds\Reporting\SortOrder;
use BingAds\Reporting\AccountPerformanceReportRequest;
use BingAds\Reporting\AccountReportScope;
use BingAds\Reporting\AccountPerformanceReportColumn;
use BingAds\Reporting\CampaignPerformanceReportColumn;
use BingAds\Reporting\CampaignPerformanceReportRequest;
use BingAds\Reporting\AccountPerformanceReportFilter;

// Specify the BingAds\Proxy object that will be used.
use BingAds\Proxy\ClientProxy;

error_reporting(E_ALL);
ini_set('display_errors', TRUE);
ini_set('display_startup_errors', TRUE);

define('EOL',(PHP_SAPI == 'cli') ? PHP_EOL : '<br />');

date_default_timezone_set('America/Chicago');



// FETCH DYNAMIC GOOGLE SHEET DATA FROM DATABASE



$sql = "SELECT * FROM id";

$g2col = $conn->query($sql);

while($row = mysqli_fetch_array($g2col)) {
        
    $genIds= array();
    $genIds['sheet'] = $row['sheet'];
    $genIds['t2'] = $row['t2'];
    $genIds['t3'] = $row['t3'];                
    $genIdsList[] = $genIds;
          
}

$worksheetTitle = $genIdsList[0]['sheet'];
$worksheetTab = $genIdsList[0]['t3'];



// SET OTHER GOOGLE SHEET API INFORMATION



$fileId = "";
$clientId = "";
$clientEmail = "";
$pathToP12File = "";



// FETCH USER COLUMN SELECTION DATA FROM DATABASE



$sql = "SELECT * FROM columns";

$g2col = $conn->query($sql);

while($row = mysqli_fetch_array($g2col)) {
        
    $columns= array();
    $columns['tier'] = $row['tier'];
    $columns['c1'] = $row['c1'];
    $columns['c2'] = $row['c2'];
    $columns['c3'] = $row['c3'];                 
    $columnsList[] = $columns;
          
}




$key = array_search('b3', array_column($columnsList, 'tier'));
$one = $columnsList[$key]['c1'];
$two = $columnsList[$key]['c2'];
$three = $columnsList[$key]['c3'];



// EMPTY DESTINATION FOLDERS OF OLD REPORTS



$files = glob(dirname(__FILE__) . '/../Reports/BingT3Pacing/*'); // get all file names
foreach($files as $file){ // iterate files
  if(is_file($file))
    unlink($file); 
}



// LOAD GOOGLE SHEET API CLASSES



use Google\Spreadsheet\DefaultServiceRequest;
use Google\Spreadsheet\ServiceRequestFactory;
use Google\Spreadsheet\CellEntry;
use Google\Spreadsheet\CellFeed;



// FETCH GOOGLE SHEET API DATA FROM GOOGLE SERVERS



$serviceRequest = new DefaultServiceRequest(getGoogleTokenFromKeyFile($clientId, $clientEmail, $pathToP12File));
ServiceRequestFactory::setInstance($serviceRequest);
$spreadsheetService = new Google\Spreadsheet\SpreadsheetService();
$spreadsheetFeed = $spreadsheetService->getSpreadsheets();
$spreadsheet = $spreadsheetFeed->getByTitle($worksheetTitle);
$worksheetFeed = $spreadsheet->getWorksheets();
$worksheet = $worksheetFeed->getByTitle($worksheetTab);
$cellFeed = $worksheet->getCellFeed();



// GOOGLE SHEETS API REPORTING FUNCTIONS



function getGoogleTokenFromKeyFile($clientId, $clientEmail, $pathToP12File) {
    $client = new Google_Client();
    $client->setClientId($clientId);

    $cred = new Google_Auth_AssertionCredentials(
        $clientEmail,
        array('https://spreadsheets.google.com/feeds'),
        file_get_contents($pathToP12File)
    );

    $client->setAssertionCredentials($cred);

    if ($client->getAuth()->isAccessTokenExpired()) {
        $client->getAuth()->refreshTokenWithAssertion($cred);
    }

    $service_token = json_decode($client->getAccessToken());
    return $service_token->access_token;
}



// GOOGLE ADWORDS API REPORTING FUNCTIONS



// Request the report. Use the ID that the request returns to
// check for the completion of the report.

function SubmitGenerateReport($proxy, $report)
{
    // Set the request information.
    
    $request = new SubmitGenerateReportRequest();
    $request->ReportRequest = $report;

    return $proxy->GetService()->SubmitGenerateReport($request)->ReportRequestId;
}

// Check the status of the report request. The guidance of how often to poll
// for status is from every five to 15 minutes depending on the amount
// of data being requested. For smaller reports, you can poll every couple
// of minutes. You should stop polling and try again later if the request
// is taking longer than an hour.

function PollGenerateReport($proxy, $reportRequestId)
{
    // Set the request information.
    
    $request = new PollGenerateReportRequest();
    $request->ReportRequestId = $reportRequestId;

    return $proxy->GetService()->PollGenerateReport($request)->ReportRequestStatus;
}

// Using the URL that the PollGenerateReport operation returned,
// send an HTTP request to get the report and write it to the specified
// ZIP file.

function DownloadFile($reportDownloadUrl, $downloadPath)
{
    if (!$reader = fopen($reportDownloadUrl, 'rb'))
    {
        throw new Exception("Failed to open URL " . $reportDownloadUrl . ".");
    }

    if (!$writer = fopen($downloadPath, 'wb'))
    {
        fclose($reader);
        throw new Exception("Failed to create ZIP file " . $downloadPath . ".");
    }

    $bufferSize = 100 * 1024;

    while (!feof($reader))
    {
        if (false === ($buffer = fread($reader, $bufferSize)))
        {
             fclose($reader);
             fclose($writer);
             throw new Exception("Read operation from URL failed.");
        }

        if (fwrite($writer, $buffer) === false)
        {
             fclose($reader);
             fclose($writer);
             $exception = new Exception("Write operation to ZIP file failed.");
        }
    }

    fclose($reader);
    fflush($writer);
    fclose($writer);
}



// CAMPAIGN PERFORMANCE REPORT FUNCTION



function GetCampaignPerformanceReportRequest($proxy, $AccountId) 
{
      // date_default_timezone_set('UTC');
    $report = new CampaignPerformanceReportRequest();
    
    $report->Format = ReportFormat::Csv;
    $report->ReportName = 'My Campaign Performance Report';
    $report->ReturnOnlyCompleteData = false;
    $report->Aggregation = ReportAggregation::Daily;
    
    $report->Scope = new CampaignReportScope();
    $report->Scope->AccountIds = array();
    $report->Scope->AccountIds[] = $AccountId;
        
    $report->Time = new ReportTime();
    // $report->Time->PredefinedTime = ReportTimePeriod::Custom date range;



// SET DATE RANGE FROM 1ST OF THIS MONTH TO YESTERDAY



    if (date('j') == 1) {
          $highDay = date('j');
    }
    else {
          $highDay = (date('j') - 1);
    }       

    //  You may either use a custom date range or predefined time.

    $report->Time->CustomDateRangeStart = new Date();
    $report->Time->CustomDateRangeStart->Month = date('n');
    $report->Time->CustomDateRangeStart->Day = 1;
    $report->Time->CustomDateRangeStart->Year = date('Y');
    $report->Time->CustomDateRangeEnd = new Date();
    $report->Time->CustomDateRangeEnd->Month = date('n');
    $report->Time->CustomDateRangeEnd->Day = $highDay;
    $report->Time->CustomDateRangeEnd->Year = date('Y');
    
    // // If you specify a filter, results may differ from data you see in the Bing Ads web application
    // $report->Filter = new AccountPerformanceReportFilter();
    // $report->Filter->DeviceType = array (
    //     DeviceTypeReportFilter::Computer,
    //     DeviceTypeReportFilter::SmartPhone
    // );

    $report->Columns = array (
            // AccountPerformanceReportColumn::TimePeriod,
            // AccountPerformanceReportColumn::AccountId,
            CampaignPerformanceReportColumn::AccountName,
            CampaignPerformanceReportColumn::CampaignName,
            CampaignPerformanceReportColumn::Clicks,
            // AccountPerformanceReportColumn::Impressions,
            // AccountPerformanceReportColumn::Ctr,
            // AccountPerformanceReportColumn::AverageCpc,
            CampaignPerformanceReportColumn::Spend,
    );
    
    $encodedReport = new SoapVar($report, SOAP_ENC_OBJECT, 'CampaignPerformanceReportRequest', $proxy->GetNamespace());
    
    return $encodedReport;
}



// ACCOUNT PERFORMANCE REPORT FUNCTION



function GetAccountPerformanceReportRequest($proxy, $AccountId) 
{
    $report = new AccountPerformanceReportRequest();
    
    $report->Format = ReportFormat::Csv;
    $report->ReportName = 'My Account Performance Report';
    $report->ReturnOnlyCompleteData = false;
    $report->Aggregation = ReportAggregation::Daily;
    
    $report->Scope = new AccountReportScope();
    $report->Scope->AccountIds = array();
    $report->Scope->AccountIds[] = $AccountId;
        
    $report->Time = new ReportTime();
    // $report->Time->PredefinedTime = ReportTimePeriod::Custom date range;



// SET DATE RANGE FROM 1ST OF THIS MONTH TO YESTERDAY


    
    //  You may either use a custom date range or predefined time.
        
        if (date('j') == 1) {
          $highDay = date('j');
        }
        else {
          $highDay = (date('j') - 1);
        }

     $report->Time->CustomDateRangeStart = new Date();
     $report->Time->CustomDateRangeStart->Month = date('n');
     $report->Time->CustomDateRangeStart->Day = 1;      
     $report->Time->CustomDateRangeStart->Year = date('Y');
     $report->Time->CustomDateRangeEnd = new Date();
     $report->Time->CustomDateRangeEnd->Month = date('n');
     $report->Time->CustomDateRangeEnd->Day = $highDay;
     $report->Time->CustomDateRangeEnd->Year = date('Y');
    
    //  If you specify a filter, results may differ from data you see in the Bing Ads web application
    //  $report->Filter = new AccountPerformanceReportFilter();
    //  $report->Filter->DeviceType = array (
    //      DeviceTypeReportFilter::Computer,
    //      DeviceTypeReportFilter::SmartPhone
    //  );

    $report->Columns = array (
            // AccountPerformanceReportColumn::TimePeriod,
            // AccountPerformanceReportColumn::TimePeriod,
            AccountPerformanceReportColumn::AccountName,
            // AccountPerformanceReportColumn::Clicks,
            // AccountPerformanceReportColumn::Impressions,
            // AccountPerformanceReportColumn::Ctr,
            // AccountPerformanceReportColumn::AverageCpc,
            AccountPerformanceReportColumn::Spend,
    );
    
    $encodedReport = new SoapVar($report, SOAP_ENC_OBJECT, 'AccountPerformanceReportRequest', $proxy->GetNamespace());
    
    return $encodedReport;

}



// EMPTY DESTINATION FOLDERS OF OLD REPORTS



$files = glob(dirname(__FILE__) . '/../Reports/BingT3PacingExt/*'); // get all file names
foreach($files as $file){ // iterate files
  if(is_file($file))
    unlink($file); 
 
}

$files = glob(dirname(__FILE__) . '/../Reports/BingT3PacingExtTest/*'); // get all file names
foreach($files as $file){ // iterate files
  if(is_file($file))
    unlink($file); 
 
}



echo "<br/>ERASING OLD FIGURES FROM RANGE ON GOOGLE SHEET";



$sheetArray = $cellFeed->toArray();

$batchRequest = new Google\Spreadsheet\Batch\BatchRequest();
for($y=2;$y<100;$y++) {
  $batchRequest->addEntry($cellFeed->createInsertionCell($y, $one, ""));
  $batchRequest->addEntry($cellFeed->createInsertionCell($y, $two, ""));
  $batchRequest->addEntry($cellFeed->createInsertionCell($y, $three, ""));
}
$batchResponse = $cellFeed->insertBatch($batchRequest);



echo "<br/>INSERTING TIME STAMP ON LAST ROW OF GOOGLE SHEET FOR CURRENTLY EXECUTING SCRIPT";



end($sheetArray);
$dateRow = key($sheetArray);

if(isset($sheetArray[$dateRow][1])) {
    $dateRow = (int)$dateRow + 2;
}

$lowDay = date('n-1-Y');
if (date('j') == 1) {
    $highDay = date('j');
}
else {
    $highDay = (date('j') - 1);
}  

$prependLD = date('n-');
$appendLD = date('-Y');
$now = date("F j, Y, g:i a");

$batchRequest = new Google\Spreadsheet\Batch\BatchRequest();
$batchRequest->addEntry($cellFeed->createInsertionCell($dateRow, $one, "Date Range: $lowDay - $prependLD$highDay$appendLD (figures pulled on $now)"));
$batchResponse = $cellFeed->insertBatch($batchRequest);



echo "<br/>FETCHING ACCOUNT LIST FROM DATABASE";



$accounts = json_decode($regJSON);



echo "<br/>CHECKING THAT THERE ARE NO ACCOUNTS IN DATABASE THAT CANNOT BE FOUND IN GOOGLE SHEET DESTINATION";



$sheetArray = array_values($sheetArray);

$columnArray = [];
for($testingSheetRow=0; $testingSheetRow < count($sheetArray); $testingSheetRow++) {
    if($sheetArray[$testingSheetRow][1]){
        $columnArray[] = $sheetArray[$testingSheetRow][1];
    }
}

$columnAccountsArray = [];
for($testingSheetRow=0; $testingSheetRow < count($accounts); $testingSheetRow++) {
    $columnAccountsArray[] = $accounts[$testingSheetRow]->name;
}

$columnArray = array_values(array_filter($columnArray));
$columnAccountsArray = array_values(array_filter($columnAccountsArray));

$foundMarker = 0;
for($accountTestRow=0; $accountTestRow < count($columnAccountsArray); $accountTestRow++) {

    $foundMarker = 0;
    $accountTestName = $columnAccountsArray[$accountTestRow];

    for($columnTestRow=0; $columnTestRow < count($columnArray); $columnTestRow++) {

    $columnTestName = $columnArray[$columnTestRow];
        if (stripos($columnTestName, trim($accountTestName)) !== false) {
            $foundMarker = 1;
        }
    }

// THROW ERROR

    if ($foundMarker == 0) {
    echo "<br/><h1>$accountTestName Not Found in Google Sheet</h1>";
    echo "<script type=\"text/javascript\">window.location.href = \"\";</script>";
    exit;
  }

}



echo "<br/>ITERATING OVER REGULAR ACCOUNT LIST AND DOWNLOADING REPORTS";



$myCount = count($accounts);

for ($row = 0; $row < $myCount; $row++) {

    // SET SPECIFIC ACCOUNT DETAILS

    $bingName = (string)$accounts[$row]->name;
    $AccountId = (string)$accounts[$row]->code;
    $sheetFilter = (string)$accounts[$row]->filter;   

    // Disable WSDL caching.

    ini_set("soap.wsdl_cache_enabled", "0");
    ini_set("soap.wsdl_cache_ttl", "0");

    // Specify your credentials.

    // $UserName = $accessToken;
    // $Password = $refreshToken;
    $DeveloperToken = "";
    $CustomerId = "";

    // Reporting WSDL.

    $wsdl = "https://api.bingads.microsoft.com/Api/Advertiser/Reporting/V9/ReportingService.svc?singleWsdl";

    // Specify the file to download the report to. Because the file is
    // compressed use the .zip file extension.

    $DownloadPath = "../Reports/BingT3Pacing/" . $bingName . $sheetFilter . ".zip";



// REQUEST CAMPAIGN PERFORMANCE REPORT



    try
    {
        // For Managing User Authentication with OAuth, replace the UserName and Password elements with the AuthenticationToken, which is your OAuth access token.
        $proxy = ClientProxy::ConstructWithAccountAndCustomerId($wsdl, null, null, $DeveloperToken, $AccountId, $CustomerId, $accessToken);
        
        // You can submit one of the example reports, or build your own.
        $report = GetCampaignPerformanceReportRequest($proxy, $AccountId);
        // $report = GetAudiencePerformanceReportRequest($proxy, $AccountId);
        //$report = GetKeywordPerformanceReportRequest($proxy, $AccountId);
        
        // SubmitGenerateReport helper method calls the corresponding Bing Ads service operation
        // to request the report identifier. The identifier is used to check report generation status
        // before downloading the report.
        
        $reportRequestId = SubmitGenerateReport(
                $proxy, 
                $report
                );
        
        printf("Report Request ID: %s\n\n", $reportRequestId);
        
         
        $reportRequestStatus = null;
        
        // This sample polls every 30 seconds up to 5 minutes.
        // In production you may poll the status every 1 to 2 minutes for up to one hour.
        // If the call succeeds, stop polling. If the call or 
        // download fails, the call throws a fault.
        
        for ($i = 0; $i < 1000; $i++)
        {
            sleep(1);
        
            // PollGenerateReport helper method calls the corresponding Bing Ads service operation
            // to get the report request status.
            
            $reportRequestStatus = PollGenerateReport(
                    $proxy, 
                    $reportRequestId
                    );
        
            if ($reportRequestStatus->Status == ReportRequestStatusType::Success ||
                $reportRequestStatus->Status == ReportRequestStatusType::Error)
            {
                break;
            }
        }

        if ($reportRequestStatus != null)
        {
            if ($reportRequestStatus->Status == ReportRequestStatusType::Success)
            {
                $reportDownloadUrl = $reportRequestStatus->ReportDownloadUrl;
                printf("Downloading from %s.\n\n", $reportDownloadUrl);
                DownloadFile($reportDownloadUrl, $DownloadPath);
                printf("The report was written to %s.\n", $DownloadPath);



// ZIP AND RENAME CONTENTS TO MATCH ACCOUNT



                $zip = new ZipArchive;
                $res = $zip->open("../Reports/BingT3Pacing/" . $bingName . $sheetFilter . ".zip");
                if ($res === TRUE) {

                  $filename = $zip->getNameIndex(0);
                  $zip->renameName($filename, $bingName . $sheetFilter . ".csv");
                  $zip->extractTo('../Reports/BingT3PacingExt/', $bingName . $sheetFilter . ".csv");
                  $zip->close();

                } else {
                  echo 'doh!';
                }

                $dir    = '../Reports/BingT3PacingExt/';
                $files = scandir($dir);
                $thisCount = count($files);
                $thisFile = $thisCount - 1;

            }
            else if ($reportRequestStatus->Status == ReportRequestStatusType::Error)
            {
                printf("The request failed. Try requesting the report " .
                        "later.\nIf the request continues to fail, contact support.\n");
            }
            else  // Pending
            {
                printf("The request is taking longer than expected.\n " .
                        "Save the report ID (%s) and try again later.\n",
                        $reportRequestId);
            }
        }
        
    }
    catch (SoapFault $e)
    {
        // Output the last request/response.

        print "\nLast SOAP request/response:\n";
        print $proxy->GetWsdl() . "\n";
        print $proxy->GetService()->__getLastRequest()."\n";
        print $proxy->GetService()->__getLastResponse()."\n";
         
        // Reporting service operations can throw AdApiFaultDetail.
        if (isset($e->detail->AdApiFaultDetail))
        {
            // Log this fault.

            print "The operation failed with the following faults:\n";

            $errors = is_array($e->detail->AdApiFaultDetail->Errors->AdApiError)
            ? $e->detail->AdApiFaultDetail->Errors->AdApiError
            : array('AdApiError' => $e->detail->AdApiFaultDetail->Errors->AdApiError);

            // If the AdApiError array is not null, the following are examples of error codes that may be found.
            foreach ($errors as $error)
            {
                print "AdApiError\n";
                printf("Code: %d\nError Code: %s\nMessage: %s\n", $error->Code, $error->ErrorCode, $error->Message);

                switch ($error->Code)
                {
                    case 0:    // InternalError
                        break;
                    case 105:  // InvalidCredentials
                        break;
                    default:
                        print "Please see MSDN documentation for more details about the error code output above.\n";
                        break;
                }
            }
        }

        // Reporting service operations can throw ApiFaultDetail.
        elseif (isset($e->detail->ApiFaultDetail))
        {
            // Log this fault.

            print "The operation failed with the following faults:\n";

            // If the BatchError array is not null, the following are examples of error codes that may be found.
            if (!empty($e->detail->ApiFaultDetail->BatchErrors))
            {
                $errors = is_array($e->detail->ApiFaultDetail->BatchErrors->BatchError)
                ? $e->detail->ApiFaultDetail->BatchErrors->BatchError
                : array('BatchError' => $e->detail->ApiFaultDetail->BatchErrors->BatchError);

                foreach ($errors as $error)
                {
                    printf("BatchError at Index: %d\n", $error->Index);
                    printf("Code: %d\nError Code: %s\nMessage: %s\n", $error->Code, $error->ErrorCode, $error->Message);

                    switch ($error->Code)
                    {
                        case 0:     // InternalError
                            break;
                        default:
                            print "Please see MSDN documentation for more details about the error code output above.\n";
                            break;
                    }
                }
            }

            // If the OperationError array is not null, the following are examples of error codes that may be found.
            if (!empty($e->detail->ApiFaultDetail->OperationErrors))
            {
                $errors = is_array($e->detail->ApiFaultDetail->OperationErrors->OperationError)
                ? $e->detail->ApiFaultDetail->OperationErrors->OperationError
                : array('OperationError' => $e->detail->ApiFaultDetail->OperationErrors->OperationError);

                foreach ($errors as $error)
                {
                    print "OperationError\n";
                    printf("Code: %d\nError Code: %s\nMessage: %s\n", $error->Code, $error->ErrorCode, $error->Message);

                    switch ($error->Code)
                    {
                        case 0:     // InternalError
                            break;
                        case 106:   // UserIsNotAuthorized
                            break;
                        case 2100:  // ReportingServiceInvalidReportId
                            break;
                        default:
                            print "Please see MSDN documentation for more details about the error code output above.\n";
                            break;
                    }
                }
            }
        }
    }
    catch (Exception $e)
    {
        if ($e->getPrevious())
        {
            ; // Ignore fault exceptions that we already caught.
        }
        else
        {
            print $e->getCode()." ".$e->getMessage()."\n\n";
            print $e->getTraceAsString()."\n\n";
        }
    }



// REQUEST ACCOUNT PERFORMANCE REPORT    



    $DownloadPath = "../Reports/BingT3Pacing/" . $bingName . $sheetFilter . "Test.zip";

    try
    {
        // For Managing User Authentication with OAuth, replace the UserName and Password elements with the AuthenticationToken, which is your OAuth access token.
        $proxy = ClientProxy::ConstructWithAccountAndCustomerId($wsdl, null, null, $DeveloperToken, $AccountId, $CustomerId, $accessToken);
        
        // You can submit one of the example reports, or build your own.
        $report = GetAccountPerformanceReportRequest($proxy, $AccountId);
        // $report = GetAudiencePerformanceReportRequest($proxy, $AccountId);
        //$report = GetKeywordPerformanceReportRequest($proxy, $AccountId);
        
        // SubmitGenerateReport helper method calls the corresponding Bing Ads service operation
        // to request the report identifier. The identifier is used to check report generation status
        // before downloading the report.
        
        $reportRequestId = SubmitGenerateReport(
                $proxy, 
                $report
                );
        
        printf("Report Request ID: %s\n\n", $reportRequestId);
        
         
        $reportRequestStatus = null;
        
        // This sample polls every 30 seconds up to 5 minutes.
        // In production you may poll the status every 1 to 2 minutes for up to one hour.
        // If the call succeeds, stop polling. If the call or 
        // download fails, the call throws a fault.
        
        for ($i = 0; $i < 1000; $i++)
        {
            sleep(1);
        
            // PollGenerateReport helper method calls the corresponding Bing Ads service operation
            // to get the report request status.
            
            $reportRequestStatus = PollGenerateReport(
                    $proxy, 
                    $reportRequestId
                    );
        
            if ($reportRequestStatus->Status == ReportRequestStatusType::Success ||
                $reportRequestStatus->Status == ReportRequestStatusType::Error)
            {
                break;
            }
        }

        if ($reportRequestStatus != null)
        {
            if ($reportRequestStatus->Status == ReportRequestStatusType::Success)
            {
                $reportDownloadUrl = $reportRequestStatus->ReportDownloadUrl;
                printf("Downloading from %s.\n\n", $reportDownloadUrl);
                DownloadFile($reportDownloadUrl, $DownloadPath);
                printf("The report was written to %s.\n", $DownloadPath);



// ZIP AND RENAME CONTENTS



                $zip = new ZipArchive;
                $res = $zip->open("../Reports/BingT3Pacing/" . $bingName . $sheetFilter . "Test.zip");
                if ($res === TRUE) {
                  
                  $filename = $zip->getNameIndex(0);

                  $zip->renameName($filename, $bingName . $sheetFilter . "Test.csv");
                  $zip->extractTo('../Reports/BingT3PacingExtTest/', $bingName . $sheetFilter . "Test.csv");
                  $zip->close();

                } else {
                  echo 'doh!';
                }

                $dir    = '../Reports/BingT3PacingExtTest/';
                $files = scandir($dir);
                $thisCount = count($files);
                $thisFile = $thisCount - 1;

                

            }
            else if ($reportRequestStatus->Status == ReportRequestStatusType::Error)
            {
                printf("The request failed. Try requesting the report " .
                        "later.\nIf the request continues to fail, contact support.\n");
            }
            else  // Pending
            {
                printf("The request is taking longer than expected.\n " .
                        "Save the report ID (%s) and try again later.\n",
                        $reportRequestId);
            }
        }
        
    }
    catch (SoapFault $e)
    {
        // Output the last request/response.

        print "\nLast SOAP request/response:\n";
        print $proxy->GetWsdl() . "\n";
        print $proxy->GetService()->__getLastRequest()."\n";
        print $proxy->GetService()->__getLastResponse()."\n";
         
        // Reporting service operations can throw AdApiFaultDetail.
        if (isset($e->detail->AdApiFaultDetail))
        {
            // Log this fault.

            print "The operation failed with the following faults:\n";

            $errors = is_array($e->detail->AdApiFaultDetail->Errors->AdApiError)
            ? $e->detail->AdApiFaultDetail->Errors->AdApiError
            : array('AdApiError' => $e->detail->AdApiFaultDetail->Errors->AdApiError);

            // If the AdApiError array is not null, the following are examples of error codes that may be found.
            foreach ($errors as $error)
            {
                print "AdApiError\n";
                printf("Code: %d\nError Code: %s\nMessage: %s\n", $error->Code, $error->ErrorCode, $error->Message);

                switch ($error->Code)
                {
                    case 0:    // InternalError
                        break;
                    case 105:  // InvalidCredentials
                        break;
                    default:
                        print "Please see MSDN documentation for more details about the error code output above.\n";
                        break;
                }
            }
        }

        // Reporting service operations can throw ApiFaultDetail.
        elseif (isset($e->detail->ApiFaultDetail))
        {
            // Log this fault.

            print "The operation failed with the following faults:\n";

            // If the BatchError array is not null, the following are examples of error codes that may be found.
            if (!empty($e->detail->ApiFaultDetail->BatchErrors))
            {
                $errors = is_array($e->detail->ApiFaultDetail->BatchErrors->BatchError)
                ? $e->detail->ApiFaultDetail->BatchErrors->BatchError
                : array('BatchError' => $e->detail->ApiFaultDetail->BatchErrors->BatchError);

                foreach ($errors as $error)
                {
                    printf("BatchError at Index: %d\n", $error->Index);
                    printf("Code: %d\nError Code: %s\nMessage: %s\n", $error->Code, $error->ErrorCode, $error->Message);

                    switch ($error->Code)
                    {
                        case 0:     // InternalError
                            break;
                        default:
                            print "Please see MSDN documentation for more details about the error code output above.\n";
                            break;
                    }
                }
            }

            // If the OperationError array is not null, the following are examples of error codes that may be found.
            if (!empty($e->detail->ApiFaultDetail->OperationErrors))
            {
                $errors = is_array($e->detail->ApiFaultDetail->OperationErrors->OperationError)
                ? $e->detail->ApiFaultDetail->OperationErrors->OperationError
                : array('OperationError' => $e->detail->ApiFaultDetail->OperationErrors->OperationError);

                foreach ($errors as $error)
                {
                    print "OperationError\n";
                    printf("Code: %d\nError Code: %s\nMessage: %s\n", $error->Code, $error->ErrorCode, $error->Message);

                    switch ($error->Code)
                    {
                        case 0:     // InternalError
                            break;
                        case 106:   // UserIsNotAuthorized
                            break;
                        case 2100:  // ReportingServiceInvalidReportId
                            break;
                        default:
                            print "Please see MSDN documentation for more details about the error code output above.\n";
                            break;
                    }
                }
            }
        }
    }
    catch (Exception $e)
    {
        if ($e->getPrevious())
        {
            ; // Ignore fault exceptions that we already caught.
        }
        else
        {
            print $e->getCode()." ".$e->getMessage()."\n\n";
            print $e->getTraceAsString()."\n\n";
        }
    }

}



echo "<br/>END ITERATION FOR REGULAR ACCOUNTS";



echo "<br/>FETCH CAMPAIGN LIST AND PULL INTO ARRAY";



$campaignList = json_decode($regCampJSON);
$campaignCount = count($campaignList);
$regCampaignsArray = array();

for ($campaignRow = 0; $campaignRow < $campaignCount; $campaignRow++) {

    $each = $campaignList[$campaignRow];

    $campaignnArray = array();

    for ($testRow = 1; $testRow <= 10; $testRow++) {

        $property =  'campaign' . $testRow;

        if ($each->$property !== '') {

            if (!isset($regCampaignsArray[$each->code])) {

                $regCampaignsArray[$each->code] = array();

            }
         
        $regCampaignsArray[$each->code][$each->filter] = array(); 
        $campaignnArray[] = $each->$property; 
        $regCampaignsArray[$each->code][$each->filter] = $campaignnArray; 

        } 
    }
}



echo "</br>FETCHING DATA FROM DOWNLOADED REPORTS";



// PREP FOR GOOGLE SHEETS INSERTION



$sheetArray = $cellFeed->toArray();
$arrayKeys = (array_keys($sheetArray));
$campaignsArrayCount = count($regCampaignsArray);
$countMarker = 0;



// ITERATE OVER ACCOUNTS AND PULL DATA



for ($thisRow = 0; $thisRow < $myCount; $thisRow++) {



// SET ACCOUNT SPECIFIC INFO



    $markAsTwin = FALSE;
    $codeNumber = $accounts[$thisRow]->code;
    $option = $accounts[$thisRow]->name;
    $filter = $accounts[$thisRow]->filter;
    $thisFormula = $accounts[$thisRow]->formula;



// DETERMINE IF ACCOUNT NEEDS CAMPAIGN INFO



    for ($thisCampaignArrayRow = 0; $thisCampaignArrayRow < $campaignsArrayCount; $thisCampaignArrayRow++) {

        if (isset($regCampaignsArray[$codeNumber])) { 

            $thisTestCount = count($regCampaignsArray[$codeNumber]);

            for ($thisGranularCampaignArrayRow = 0; $thisGranularCampaignArrayRow < $thisTestCount; $thisGranularCampaignArrayRow++) {

                if (isset($regCampaignsArray[$codeNumber][$filter]) || isset($regCampaignsArray[$codeNumber][strtolower($filter)])) {

                    $indCampaignCount = count($regCampaignsArray[$codeNumber][$filter]);
                    $countMarker = 1;

                }
            }
        }
    }



// PHPEXCEL OPEN SPREADSHEET 



    $objReader = new PHPExcel_Reader_CSV();
    $objPHPExcel = $objReader->load("../Reports/BingT3PacingExt/" . $option . $filter . ".csv");
    $objWorksheet = $objPHPExcel->getActiveSheet();



// SEND TO ARRAY



    $clicksArray = array();
    $spendArray = array();

    $highestRow = $objWorksheet->getHighestRow(); // e.g. 10
    $highestColumn = $objWorksheet->getHighestColumn(); // e.g 'F'
    $highestColumnIndex = PHPExcel_Cell::columnIndexFromString($highestColumn); // e.g. 5



// ITERATE THROUGH SPREADSHEET ARRAY AND PULL CLICKS AND SPEND DATA / DETERMINE CAMPAIGN INFO



    for ($row = 1; $row <= $highestRow; ++$row) {
        for ($col = 1; $col <= $highestColumnIndex; ++$col) {

        $contents = $objWorksheet->getCellByColumnAndRow($col, $row)->getValue();

            if ($countMarker == 0) {

                if (($col == 2) && (is_numeric($contents)))  {

                    $clicksArray[] = $contents;

                }
                elseif (($col == 3) && (is_numeric($contents))) {

                    $spendArray[] = $contents;

                }
            }
                                                    
            if ($countMarker == 1) {
                $markAsTwin = TRUE;
                
                for ($indCampaignRow = 0; $indCampaignRow < $indCampaignCount; $indCampaignRow++) {

                    if (strtolower(trim($contents)) == strtolower(trim($regCampaignsArray[$codeNumber][$filter][$indCampaignRow]))) {

                    $clicksArray[] = $objWorksheet->getCellByColumnAndRow(($col + 1), $row)->getValue();
                    $spendArray[] = $objWorksheet->getCellByColumnAndRow(($col + 2), $row)->getValue();

                    }
                }                               
            }
        }
    }

    $countMarker = 0;



// SAVE TOTAL SPEND AND CLICKS IN VAR



    $bingClicks = array_sum($clicksArray);
    $bingSpend = array_sum($spendArray);



// OPEN 2ND REPORT FOR CROSS REFERENCE



    $objReader = new PHPExcel_Reader_CSV();
    $objPHPExcel = $objReader->load("../Reports/BingT3PacingExtTest/" . $option . $filter . "Test.csv");
    $objWorksheet = $objPHPExcel->getActiveSheet();

 

// SAVE CROSS REFERENCE FIGURES IN VAR



    $testSpend = $objWorksheet->getCellByColumnAndRow(1, 12)->getValue();
    $testAccount = $objWorksheet->getCellByColumnAndRow(0, 12)->getValue();
    $testDates = $objWorksheet->getCellByColumnAndRow(0, 5)->getValue();
    $testDates = substr($testDates, -26);
    $testSpend = number_format($testSpend, 2, '.', '');
    $bingSpend = number_format($bingSpend, 2, '.', '');



// COMPARE FIGURES AND THROW ERROR FOR DISCREPENCY



    $errorMessage = "";

    if ($testSpend != $bingSpend) {
      $errorMessage = "Bing Warning: discrepency found between $option $filter presumed entry: $bingSpend and $testAccount account total: $testSpend";
    }



// FIND GOOGLE SHEET ROW THAT MATCHES ACCOUNT NAME



    $o = 0;
    foreach($sheetArray as $item)
    {
        foreach($item as $sub => $v)
        {
            if (($filter !== "") && (isset($item[1])) && ((stripos($item[1], trim($option)) !== false)) && (stripos($item[2], trim($filter)) !== false)) {

                $googleSheetRow = $arrayKeys[$o];

            }
            elseif (($filter == "") && (isset($item[1])) && (stripos($item[1], trim($option)) !== false)) {

            $googleSheetRow = $arrayKeys[$o];

            }
        }
      
    $o++;

    }



// DETERMINE DUPLICATE ACCOUNTS FOR FORMULA INSERTION



    if ($markAsTwin) {
        $errorMessage = "Campaign spend of total spend: $testSpend";
    }

    if ($thisFormula == 1) {



    echo "<br/>INSERTING FORMULA WITH SPEND DATA INTO SPREADSHEET"; 



        $findMyTwins = array();
        $y = 0;

        foreach($sheetArray as $item)
        {
            foreach($item as $sub => $v)
            {

                if ((isset($item[1])) && (stripos($item[1], trim($option)) !== false)) {

                    if(!in_array($arrayKeys[$y], $findMyTwins, true)){
                        array_push($findMyTwins, $arrayKeys[$y]);
                    }

                }
            }

        $y++;

        }
 
        $pos = array_search($googleSheetRow, $findMyTwins);
        unset($findMyTwins[$pos]);
        $findMyTwins = array_values($findMyTwins);
        $twinCount = count($findMyTwins);



// IF THERE ARE FORMULAS SELECTED BY USER, FIND LETTER VALUE



        $letters = range('A', 'Z');
        $formulaColSpend = $letters[$one-1];

        if ($twinCount == 1) {
          $batchRequest = new Google\Spreadsheet\Batch\BatchRequest();
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $one, "=$bingSpend-$formulaColSpend$findMyTwins[0]"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $two, "$errorMessage"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $three, "$testAccount"));
          $batchResponse = $cellFeed->insertBatch($batchRequest);
        }
        elseif ($twinCount == 2) {
          $batchRequest = new Google\Spreadsheet\Batch\BatchRequest();
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $one, "=$bingSpend-$formulaColSpend$findMyTwins[0]-$formulaColSpend$findMyTwins[1]"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $two, "$errorMessage"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $three, "$testAccount"));
          $batchResponse = $cellFeed->insertBatch($batchRequest);
        }
        elseif ($twinCount == 3) {
          $batchRequest = new Google\Spreadsheet\Batch\BatchRequest();
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $one, "=$bingSpend-$formulaColSpend$findMyTwins[0]-$formulaColSpend$findMyTwins[1]-$formulaColSpend$findMyTwins[2]"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $two, "$errorMessage"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $three, "$testAccount"));
          $batchResponse = $cellFeed->insertBatch($batchRequest);
        }
        elseif ($twinCount == 4) {
          $batchRequest = new Google\Spreadsheet\Batch\BatchRequest();
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $one, "=$bingSpend-$formulaColSpend$findMyTwins[0]-$formulaColSpend$findMyTwins[1]-$formulaColSpend$findMyTwins[2]-$formulaColSpend$findMyTwins[3]"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $two, "$errorMessage"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $three, "$testAccount"));
          $batchResponse = $cellFeed->insertBatch($batchRequest);
        }
        elseif ($twinCount == 5) {
          $batchRequest = new Google\Spreadsheet\Batch\BatchRequest();
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $one, "=$bingSpend-$formulaColSpend$findMyTwins[0]-$formulaColSpend$findMyTwins[1]-$formulaColSpend$findMyTwins[2]-$formulaColSpend$findMyTwins[3]-$formulaColSpend$findMyTwins[4]"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $two, "$errorMessage"));
          $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $three, "$testAccount"));
          $batchResponse = $cellFeed->insertBatch($batchRequest);
        }
        else {
        $batchRequest = new Google\Spreadsheet\Batch\BatchRequest();
        $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $one, "$bingSpend"));
        $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $two, "$errorMessage"));
        $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $three, "$testAccount"));
        $batchResponse = $cellFeed->insertBatch($batchRequest);
        }
    }
    else {



        echo "<br/>INSERTING SPEND DATA INTO SPREADSHEET"; 



        $batchRequest = new Google\Spreadsheet\Batch\BatchRequest();
        $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $one, "$bingSpend"));
        $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $two, "$errorMessage"));
        $batchRequest->addEntry($cellFeed->createInsertionCell($googleSheetRow, $three, "$testAccount"));
        $batchResponse = $cellFeed->insertBatch($batchRequest);
    }
}

?>