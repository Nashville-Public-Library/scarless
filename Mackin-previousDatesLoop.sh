#!/bin/bash

# James Staub, Nashville Public Library
# Mackin-previousDatesLoop.sh
# This script processes data for a range of dates by calling NashvilleMNPSDataWarehouseReport-Mackin.sh
# for each date in the range.

# Display usage information if incorrect arguments are provided
if [ $# -ne 2 ]; then
  echo "Usage: $0 <start_date> <stop_date>"
  echo "Both dates must be in YYYY-MM-DD format"
  echo "Example: $0 2025-03-01 2025-04-30"
  exit 1
fi

start_date=$1
stop_date=$2

# Validate date formats
if ! date -d "$start_date" >/dev/null 2>&1; then
  echo "Error: Invalid start date format. Please use YYYY-MM-DD."
  exit 1
fi

if ! date -d "$stop_date" >/dev/null 2>&1; then
  echo "Error: Invalid stop date format. Please use YYYY-MM-DD."
  exit 1
fi

# Ensure start date is before or equal to stop date
if [[ $(date -d "$start_date" +%s) -gt $(date -d "$stop_date" +%s) ]]; then
  echo "Error: Start date must be before or equal to stop date."
  exit 1
fi

# Convert dates to seconds since epoch for comparison
start_seconds=$(date -d "$start_date" +%s)
stop_seconds=$(date -d "$stop_date" +%s)
current_seconds=$start_seconds

# Loop through all dates from start_date to stop_date
while [ $current_seconds -le $stop_seconds ]; do
  # Format the current date as YYYY-MM-DD
  current_date=$(date -d "@$current_seconds" +%Y-%m-%d)

  echo "Processing date: $current_date"

  # Run the script with the date as an argument
  ./NashvilleMNPSDataWarehouseReport-Mackin.sh "$current_date" 2>&1 >/dev/null

  # Move to the next day (add 86400 seconds = 24 hours)
  current_seconds=$((current_seconds + 86400))
done

echo "Processing complete for date range: $start_date to $stop_date"
