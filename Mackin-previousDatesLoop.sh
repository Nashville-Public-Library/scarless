#!/bin/bash

# Loop through all dates in March and April
for month in 03 04; do
  for day in {01..31}; do
    # Format the date as YYYY-MM-DD
    date="2025-$month-$day"

    # Check if the date is valid (to handle months with fewer than 31 days)
    if date -d "$date" >/dev/null 2>&1; then
      # Run the script with the date as an argument
      ./NashvilleMNPSDataWarehouseReport-Mackin.sh "$date"
    fi
  done
done