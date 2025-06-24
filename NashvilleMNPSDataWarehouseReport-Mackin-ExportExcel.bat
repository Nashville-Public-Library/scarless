@echo off
setlocal enabledelayedexpansion

REM NashvilleMNPSDataWarehouseReport-Mackin-ExportExcel.bat
REM This script exports Student and Staff records from an Excel workbook for all dates in the file
REM James Staub, Nashville Public Library (mostly written by JetBrains' Junie AI
REM Usage: NashvilleMNPSDataWarehouseReport-Mackin-ExportExcel.bat
REM The script will process all dates found in the Excel file (expected range: 2024-08-07 through 2025-03-03)

echo [%time%] Script started

REM Define file paths
REM set excel_file=..\data\mackin\MackinVIA_Report.xlsx
set excel_file=..\data\mackin\MackinVIA_20250610.xlsx
set output_dir=..\data\mackin

REM Convert relative paths to absolute paths
for %%i in ("!excel_file!") do set "excel_file_abs=%%~fi"
for %%i in ("!output_dir!") do set "output_dir_abs=%%~fi"

echo [%time%] Excel file path: !excel_file!
echo [%time%] Absolute Excel file path: !excel_file_abs!
echo [%time%] Output directory: !output_dir!
echo [%time%] Absolute output directory: !output_dir_abs!

REM Create output directory if it doesn't exist
if not exist "!output_dir!" (
    echo [%time%] Creating output directory: !output_dir!
    mkdir "!output_dir!"
)

REM Check if Excel file exists
if not exist "!excel_file_abs!" (
    echo [%time%] Error: Excel file not found at !excel_file_abs!
    echo [%time%] Please ensure the Excel workbook is in the correct location.
    exit /b 1
)

echo [%time%] Excel file exists: !excel_file_abs!
echo [%time%] Absolute path check:
dir "!excel_file_abs!" 2>nul || echo [%time%] Warning: Could not list file details

echo [%time%] Creating PowerShell script to extract data

REM Create PowerShell script with error handling and progress reporting
echo Write-Host "PowerShell script started at $(Get-Date)" > extract_excel.ps1
echo Write-Host "Attempting to open Excel file: !excel_file_abs!" >> extract_excel.ps1
echo $ErrorActionPreference = "Stop" >> extract_excel.ps1
echo try { >> extract_excel.ps1
echo     Write-Host "Creating Excel application object..." >> extract_excel.ps1
echo     $excel = New-Object -ComObject Excel.Application >> extract_excel.ps1
echo     $excel.Visible = $false >> extract_excel.ps1
echo     $excel.DisplayAlerts = $false >> extract_excel.ps1
echo     Write-Host "Excel application object created successfully" >> extract_excel.ps1
echo     Write-Host "Checking if Excel file exists..." >> extract_excel.ps1
echo     if (-not (Test-Path "!excel_file_abs!")) { >> extract_excel.ps1
echo         throw "Excel file not found at !excel_file_abs!" >> extract_excel.ps1
echo     } >> extract_excel.ps1
echo     Write-Host "Excel file exists. Attempting to open workbook..." >> extract_excel.ps1
echo     try { >> extract_excel.ps1
echo         $workbook = $excel.Workbooks.Open("!excel_file_abs!") >> extract_excel.ps1
echo         Write-Host "Workbook opened successfully" >> extract_excel.ps1
echo     } catch { >> extract_excel.ps1
echo         Write-Host "ERROR: Failed to open workbook. Exception: $_" -ForegroundColor Red >> extract_excel.ps1
echo         Write-Host "Attempting to open with additional options..." -ForegroundColor Yellow >> extract_excel.ps1
echo         try { >> extract_excel.ps1
echo             $workbook = $excel.Workbooks.Open("!excel_file_abs!", $false, $true) >> extract_excel.ps1
echo             Write-Host "Workbook opened successfully with read-only option" -ForegroundColor Green >> extract_excel.ps1
echo         } catch { >> extract_excel.ps1
echo             Write-Host "ERROR: Still failed to open workbook. Exception: $_" -ForegroundColor Red >> extract_excel.ps1
echo             Write-Host "Attempting to open with alternative method..." -ForegroundColor Yellow >> extract_excel.ps1
echo             try { >> extract_excel.ps1
echo                 # Try using the Excel.Application COM object differently >> extract_excel.ps1
echo                 $excel2 = New-Object -ComObject Excel.Application >> extract_excel.ps1
echo                 $excel2.Visible = $false >> extract_excel.ps1
echo                 $excel2.DisplayAlerts = $false >> extract_excel.ps1
echo                 # Use the full path with forward slashes instead of backslashes >> extract_excel.ps1
echo                 $altPath = "!excel_file_abs!".Replace("\", "/") >> extract_excel.ps1
echo                 Write-Host "Trying alternative path format: $altPath" -ForegroundColor Yellow >> extract_excel.ps1
echo                 $workbook = $excel2.Workbooks.Open($altPath) >> extract_excel.ps1
echo                 Write-Host "Workbook opened successfully with alternative method" -ForegroundColor Green >> extract_excel.ps1
echo                 # Release the first Excel instance >> extract_excel.ps1
echo                 $excel.Quit() >> extract_excel.ps1
echo                 [System.Runtime.Interopservices.Marshal]::ReleaseComObject($excel) ^| Out-Null >> extract_excel.ps1
echo                 # Use the new Excel instance >> extract_excel.ps1
echo                 $excel = $excel2 >> extract_excel.ps1
echo             } catch { >> extract_excel.ps1
echo                 Write-Host "ERROR: All methods failed to open workbook. Exception: $_" -ForegroundColor Red >> extract_excel.ps1
echo                 throw "Could not open Excel file: !excel_file_abs!" >> extract_excel.ps1
echo             } >> extract_excel.ps1
echo         } >> extract_excel.ps1
echo     } >> extract_excel.ps1
echo     $headers = @("MACKINVIA_ACCOUNT_ID", "YEARMONTHDAY", "USER_ID", "COUNT_OF_CHECKOUTS") >> extract_excel.ps1
echo     Write-Host "Attempting to access worksheets..." >> extract_excel.ps1
echo     $studentSheet = $workbook.Sheets.Item("Student") >> extract_excel.ps1
echo     Write-Host "Student worksheet accessed successfully" >> extract_excel.ps1
echo     $staffSheet = $workbook.Sheets.Item("Staff") >> extract_excel.ps1
echo     Write-Host "Staff worksheet accessed successfully" >> extract_excel.ps1
echo     $uniqueDates = @{} >> extract_excel.ps1
echo     Write-Host "Extracting unique dates from Student worksheet..." >> extract_excel.ps1
echo     $studentRowCount = $studentSheet.UsedRange.Rows.Count >> extract_excel.ps1
echo     Write-Host "Student worksheet has $studentRowCount rows" >> extract_excel.ps1
echo     for ($i = 2; $i -le $studentRowCount; $i++) { >> extract_excel.ps1
echo         if ($i %% 1000 -eq 0) { Write-Host "Processing Student row $i of $studentRowCount..." } >> extract_excel.ps1
echo         $date = $studentSheet.Cells.Item($i, 2).Text >> extract_excel.ps1
echo         if ($date -match '^\d{4}-\d{2}-\d{2}$') { >> extract_excel.ps1
echo             $uniqueDates[$date] = $true >> extract_excel.ps1
echo         } >> extract_excel.ps1
echo     } >> extract_excel.ps1
echo     Write-Host "Finished extracting dates from Student worksheet" >> extract_excel.ps1

echo     Write-Host "Extracting unique dates from Staff worksheet..." >> extract_excel.ps1
echo     $staffRowCount = $staffSheet.UsedRange.Rows.Count >> extract_excel.ps1
echo     Write-Host "Staff worksheet has $staffRowCount rows" >> extract_excel.ps1
echo     for ($i = 2; $i -le $staffRowCount; $i++) { >> extract_excel.ps1
echo         if ($i %% 1000 -eq 0) { Write-Host "Processing Staff row $i of $staffRowCount..." } >> extract_excel.ps1
echo         $date = $staffSheet.Cells.Item($i, 2).Text >> extract_excel.ps1
echo         if ($date -match '^\d{4}-\d{2}-\d{2}$') { >> extract_excel.ps1
echo             $uniqueDates[$date] = $true >> extract_excel.ps1
echo         } >> extract_excel.ps1
echo     } >> extract_excel.ps1
echo     Write-Host "Finished extracting dates from Staff worksheet" >> extract_excel.ps1

echo     Write-Host "Sorting unique dates..." >> extract_excel.ps1
echo     $dates = $uniqueDates.Keys ^| Sort-Object >> extract_excel.ps1
echo     Write-Host "Found $($dates.Count) unique dates in the Excel file" >> extract_excel.ps1

REM Process each date
echo     Write-Host "Beginning to process each date..." >> extract_excel.ps1
echo     $dateCount = 0 >> extract_excel.ps1
echo     $totalDates = $dates.Count >> extract_excel.ps1
echo     foreach ($date in $dates) { >> extract_excel.ps1
echo         $dateCount++ >> extract_excel.ps1
echo         Write-Host "Processing date $dateCount of $totalDates`: $date" >> extract_excel.ps1
echo         $dateParts = $date -split '-' >> extract_excel.ps1
echo         $year = $dateParts[0] >> extract_excel.ps1
echo         $month = $dateParts[1] >> extract_excel.ps1
echo         $day = $dateParts[2] >> extract_excel.ps1
echo         $dateFormatted = "${month}_${day}_${year}" >> extract_excel.ps1
echo         $studentOutput = "!output_dir_abs!\Nashville daily VIA report_${dateFormatted}.csv" >> extract_excel.ps1
echo         $staffOutput = "!output_dir_abs!\Nashville daily VIA report_staff_${dateFormatted}.csv" >> extract_excel.ps1
echo         Write-Host "Student output file: $studentOutput" >> extract_excel.ps1
echo         Write-Host "Staff output file: $staffOutput" >> extract_excel.ps1

REM Extract Student data for this date
echo         Write-Host "Extracting Student data for date: $date" >> extract_excel.ps1
echo         $studentData = @() >> extract_excel.ps1
echo         $studentData += $headers -join "," >> extract_excel.ps1
echo         $matchCount = 0 >> extract_excel.ps1
echo         Write-Host "Scanning $studentRowCount Student rows for matches..." >> extract_excel.ps1
echo         for ($i = 2; $i -le $studentRowCount; $i++) { >> extract_excel.ps1
echo             if ($i %% 5000 -eq 0) { Write-Host "Scanning Student row $i of $studentRowCount..." } >> extract_excel.ps1
echo             $yearMonthDay = $studentSheet.Cells.Item($i, 2).Text >> extract_excel.ps1
echo             if ($yearMonthDay -eq $date) { >> extract_excel.ps1
echo                 $matchCount++ >> extract_excel.ps1
echo                 if ($matchCount %% 100 -eq 0) { Write-Host "Found $matchCount matching Student records so far..." } >> extract_excel.ps1
echo                 $row = @() >> extract_excel.ps1
echo                 $row += $studentSheet.Cells.Item($i, 1).Text >> extract_excel.ps1
echo                 $row += $studentSheet.Cells.Item($i, 2).Text >> extract_excel.ps1
echo                 $row += $studentSheet.Cells.Item($i, 3).Text >> extract_excel.ps1
echo                 $row += $studentSheet.Cells.Item($i, 4).Text >> extract_excel.ps1
echo                 $studentData += $row -join "," >> extract_excel.ps1
echo             } >> extract_excel.ps1
echo         } >> extract_excel.ps1
echo         Write-Host "Writing Student data to file: $studentOutput" >> extract_excel.ps1
echo         $studentData ^| Out-File -FilePath $studentOutput -Encoding utf8 >> extract_excel.ps1
echo         if ($studentData.Count -eq 1) { >> extract_excel.ps1
echo             Write-Host "No student records found for date $date. Created file with headers only." >> extract_excel.ps1
echo         } else { >> extract_excel.ps1
echo             Write-Host "Created student output file with $($studentData.Count - 1) records." >> extract_excel.ps1
echo         } >> extract_excel.ps1

REM Extract Staff data for this date
echo         Write-Host "Extracting Staff data for date: $date" >> extract_excel.ps1
echo         $staffData = @() >> extract_excel.ps1
echo         $staffData += $headers -join "," >> extract_excel.ps1
echo         $matchCount = 0 >> extract_excel.ps1
echo         Write-Host "Scanning $staffRowCount Staff rows for matches..." >> extract_excel.ps1
echo         for ($i = 2; $i -le $staffRowCount; $i++) { >> extract_excel.ps1
echo             if ($i %% 5000 -eq 0) { Write-Host "Scanning Staff row $i of $staffRowCount..." } >> extract_excel.ps1
echo             $yearMonthDay = $staffSheet.Cells.Item($i, 2).Text >> extract_excel.ps1
echo             if ($yearMonthDay -eq $date) { >> extract_excel.ps1
echo                 $matchCount++ >> extract_excel.ps1
echo                 if ($matchCount %% 100 -eq 0) { Write-Host "Found $matchCount matching Staff records so far..." } >> extract_excel.ps1
echo                 $row = @() >> extract_excel.ps1
echo                 $row += $staffSheet.Cells.Item($i, 1).Text >> extract_excel.ps1
echo                 $row += $staffSheet.Cells.Item($i, 2).Text >> extract_excel.ps1
echo                 $row += $staffSheet.Cells.Item($i, 3).Text >> extract_excel.ps1
echo                 $row += $staffSheet.Cells.Item($i, 4).Text >> extract_excel.ps1
echo                 $staffData += $row -join "," >> extract_excel.ps1
echo             } >> extract_excel.ps1
echo         } >> extract_excel.ps1
echo         Write-Host "Writing Staff data to file: $staffOutput" >> extract_excel.ps1
echo         $staffData ^| Out-File -FilePath $staffOutput -Encoding utf8 >> extract_excel.ps1
echo         if ($staffData.Count -eq 1) { >> extract_excel.ps1
echo             Write-Host "No staff records found for date $date. Created file with headers only." >> extract_excel.ps1
echo         } else { >> extract_excel.ps1
echo             Write-Host "Created staff output file with $($staffData.Count - 1) records." >> extract_excel.ps1
echo         } >> extract_excel.ps1
echo     } >> extract_excel.ps1

REM Clean up
echo     Write-Host "Processing completed successfully. Cleaning up resources..." >> extract_excel.ps1
echo     $workbook.Close($false) >> extract_excel.ps1
echo     $excel.Quit() >> extract_excel.ps1
echo     [System.Runtime.Interopservices.Marshal]::ReleaseComObject($studentSheet) ^| Out-Null >> extract_excel.ps1
echo     [System.Runtime.Interopservices.Marshal]::ReleaseComObject($staffSheet) ^| Out-Null >> extract_excel.ps1
echo     [System.Runtime.Interopservices.Marshal]::ReleaseComObject($workbook) ^| Out-Null >> extract_excel.ps1
echo     [System.Runtime.Interopservices.Marshal]::ReleaseComObject($excel) ^| Out-Null >> extract_excel.ps1
echo     [System.GC]::Collect() >> extract_excel.ps1
echo     [System.GC]::WaitForPendingFinalizers() >> extract_excel.ps1
echo     Write-Host "Cleanup completed. Script finished successfully at $(Get-Date)" >> extract_excel.ps1
echo } catch { >> extract_excel.ps1
echo     Write-Host "ERROR: An exception occurred during script execution:" -ForegroundColor Red >> extract_excel.ps1
echo     Write-Host $_.Exception.Message -ForegroundColor Red >> extract_excel.ps1
echo     Write-Host "Exception details:" -ForegroundColor Red >> extract_excel.ps1
echo     Write-Host $_ -ForegroundColor Red >> extract_excel.ps1
echo     Write-Host "Stack trace:" -ForegroundColor Red >> extract_excel.ps1
echo     Write-Host $_.ScriptStackTrace -ForegroundColor Red >> extract_excel.ps1
echo     Write-Host "Checking file existence again:" -ForegroundColor Yellow >> extract_excel.ps1
echo     if (Test-Path "!excel_file_abs!") { >> extract_excel.ps1
echo         Write-Host "File exists at !excel_file_abs!" -ForegroundColor Green >> extract_excel.ps1
echo         $fileInfo = Get-Item "!excel_file_abs!" >> extract_excel.ps1
echo         Write-Host "File details: $fileInfo" -ForegroundColor Green >> extract_excel.ps1
echo         Write-Host "File size: $($fileInfo.Length) bytes" -ForegroundColor Green >> extract_excel.ps1
echo         Write-Host "Last modified: $($fileInfo.LastWriteTime)" -ForegroundColor Green >> extract_excel.ps1
echo         Write-Host "Checking file permissions..." -ForegroundColor Yellow >> extract_excel.ps1
echo         try { >> extract_excel.ps1
echo             $acl = Get-Acl "!excel_file_abs!" >> extract_excel.ps1
echo             Write-Host "File owner: $($acl.Owner)" -ForegroundColor Green >> extract_excel.ps1
echo             Write-Host "Access rules:" -ForegroundColor Green >> extract_excel.ps1
echo             $acl.Access ^| ForEach-Object { Write-Host "  $($_.IdentityReference) : $($_.FileSystemRights)" -ForegroundColor Green } >> extract_excel.ps1
echo         } catch { >> extract_excel.ps1
echo             Write-Host "Could not get file permissions: $_" -ForegroundColor Red >> extract_excel.ps1
echo         } >> extract_excel.ps1
echo         Write-Host "Testing file read access..." -ForegroundColor Yellow >> extract_excel.ps1
echo         try { >> extract_excel.ps1
echo             $testContent = [System.IO.File]::ReadAllBytes("!excel_file_abs!") >> extract_excel.ps1
echo             Write-Host "Successfully read $($testContent.Length) bytes from file" -ForegroundColor Green >> extract_excel.ps1
echo         } catch { >> extract_excel.ps1
echo             Write-Host "Failed to read file: $_" -ForegroundColor Red >> extract_excel.ps1
echo         } >> extract_excel.ps1
echo     } else { >> extract_excel.ps1
echo         Write-Host "File does NOT exist at !excel_file_abs!" -ForegroundColor Red >> extract_excel.ps1
echo     } >> extract_excel.ps1
echo     Write-Host "Attempting to clean up resources..." -ForegroundColor Yellow >> extract_excel.ps1
echo     try { >> extract_excel.ps1
echo         if ($null -ne $excel) { >> extract_excel.ps1
echo             $excel.Quit() >> extract_excel.ps1
echo             [System.Runtime.Interopservices.Marshal]::ReleaseComObject($excel) ^| Out-Null >> extract_excel.ps1
echo         } >> extract_excel.ps1
echo     } catch { >> extract_excel.ps1
echo         Write-Host "Error during cleanup: $_" -ForegroundColor Red >> extract_excel.ps1
echo     } >> extract_excel.ps1
echo     [System.GC]::Collect() >> extract_excel.ps1
echo     [System.GC]::WaitForPendingFinalizers() >> extract_excel.ps1
echo     exit 1 >> extract_excel.ps1
echo } >> extract_excel.ps1

echo [%time%] Running PowerShell script...

REM Run PowerShell script and capture exit code
powershell -ExecutionPolicy Bypass -File extract_excel.ps1
set PS_EXIT_CODE=%ERRORLEVEL%

echo [%time%] PowerShell script completed with exit code: %PS_EXIT_CODE%

REM Check if the script ran successfully
if %PS_EXIT_CODE% NEQ 0 (
    echo [%time%] ERROR: PowerShell script encountered an error. See above for details.
    echo [%time%] Preserving extract_excel.ps1 for troubleshooting.
) else (
    echo [%time%] PowerShell script completed successfully.
    echo [%time%] Cleaning up temporary files...
    del extract_excel.ps1
)

echo [%time%] Processing complete.
