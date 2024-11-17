@echo off
setlocal enabledelayedexpansion

set startYear=2017
set startMonth=07
set endYear=2024
set endMonth=10

for /L %%Y in (%startYear%,1,%endYear%) do (
    for /L %%M in (1,1,12) do (
        if %%Y==%startYear% if %%M LSS %startMonth% (
            rem Skip to the next iteration of the inner loop
            continue
        )
        if %%Y==%endYear% if %%M GTR %endMonth% (
            rem Exit the loop
            goto :end
        )
        set month=%%M
        if %%M LSS 10 set month=0%%M
        php NashvilleMNPSDataWarehouseReport.php %%Y!month!
    )
)
:end