#!/bin/bash
# NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh
# Generic loop wrapper for MNPS Data Warehouse reports
# Usage: ./NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh <script_to_run> <start_date> <stop_date> [extra_args...]

if [ $# -lt 3 ]; then
  echo "Usage: $0 <script_path> <start_date> <stop_date> [extra_args...]"
  echo "Both dates should be in YYYY-MM-DD or YYYYMMDD format"
  echo "Example: $0 ./NashvilleMNPSDataWarehouseReport-OverDrive.sh 2026-05-01 2026-05-30 -verbose"
  exit 1
fi

script_to_run=$1
start_date=$2
stop_date=$3
shift 3

# Validate script existence
if [ ! -f "$script_to_run" ]; then
  echo "Error: Script not found: $script_to_run"
  exit 1
fi

# Validate date formats and normalize to YYYY-MM-DD for the loop
if ! date -d "$start_date" >/dev/null 2>&1; then
  echo "Error: Invalid start date format: $start_date"
  exit 1
fi
start_iso=$(date -d "$start_date" +%Y-%m-%d)

if ! date -d "$stop_date" >/dev/null 2>&1; then
  echo "Error: Invalid stop date format: $stop_date"
  exit 1
fi
stop_iso=$(date -d "$stop_date" +%Y-%m-%d)

# Ensure start date is before or equal to stop date
if [[ $(date -d "$start_iso" +%s) -gt $(date -d "$stop_iso" +%s) ]]; then
  echo "Error: Start date must be before or equal to stop date."
  exit 1
fi

current_date=$start_iso
stop_date=$stop_iso

echo "Starting date loop for $script_to_run from $current_date to $stop_date"

while [[ "$current_date" < $(date -d "$stop_date + 1 day" +%Y-%m-%d) ]]; do
  echo "--------------------------------------------------------"
  echo "Processing date: $current_date"
  
  # Run the script with the date as the first argument, followed by any extra args
  if [[ "$script_to_run" == */* ]]; then
    "$script_to_run" "$current_date" "$@"
  else
    "./$script_to_run" "$current_date" "$@"
  fi
  
  # Check if the script failed
  if [ $? -ne 0 ]; then
    echo "Warning: Script failed for date $current_date"
  fi

  # Move to the next day
  current_date=$(date -d "$current_date + 1 day" +%Y-%m-%d)
done

echo "--------------------------------------------------------"
echo "Processing complete for date range: $start_iso to $stop_iso"