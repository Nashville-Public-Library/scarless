#!/bin/bash
# NashvilleMNPSDataWarehouseReport.sh
# James Staub, Nashville Public Library
# This script runs the PHP script NashvilleMNPSDataWarehouseReport.php for a range of dates and then moves the files to the destination directory.

# Check if the correct number of arguments is provided
# Check the number of arguments
if [ "$#" -eq 0 ]; then
  # No arguments: run the PHP script without arguments
  php NashvilleMNPSDataWarehouseReport.php
elif [ "$#" -eq 1 ]; then
  # One argument: pass the argument to the PHP script
  php NashvilleMNPSDataWarehouseReport.php "$1"
elif [ "$#" -gt 2 ]; then
  echo "Usage: $0 <start_date: YYYYMMDD> <end_date: YYYYMMDD>"
  exit 1
elif [ "$#" -eq 2 ]; then

  # Parse arguments
  START_DATE=$1
  END_DATE=$2

  # Convert dates to a format that allows comparison
  CURRENT_DATE=$(date -d "$START_DATE" +%Y-%m-%d)
  END_DATE=$(date -d "$END_DATE" +%Y-%m-%d)

  # Loop through each date in the range
  while [ "$CURRENT_DATE" != "$(date -d "$END_DATE + 1 day" +%Y-%m-%d)" ]; do
    # Format the current date as YYYYMMDD for the PHP script
    FORMATTED_DATE=$(date -d "$CURRENT_DATE" +%Y%m%d)

    # Run the PHP script with the current date as an argument
    php NashvilleMNPSDataWarehouseReport.php "$FORMATTED_DATE"

    # Increment the date
    CURRENT_DATE=$(date -d "$CURRENT_DATE + 1 day" +%Y-%m-%d)
  done
fi

# Move the file to the destination directory
SOURCE_FILE="../data/LibraryServices-Checkouts-*"
DEST_DIR="/home/mnps.org/data/"

# Set file ownership and permissions
chown :mnps.org $SOURCE_FILE
chmod 660 $SOURCE_FILE

# Move the files
mv $SOURCE_FILE $DEST_DIR/