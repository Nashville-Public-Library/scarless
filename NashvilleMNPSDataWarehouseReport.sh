#!/bin/bash
# NashvilleMNPSDataWarehouseReport.sh
# James Staub, Nashville Public Library
# This script runs the PHP script NashvilleMNPSDataWarehouseReport.php for a range of dates and then moves the files to the destination directory.

# Check if first two arguments are dates for a date range loop
if [[ $# -ge 2 ]] && [[ "$1" =~ ^[0-9]{4}-?[0-9]{2}-?[0-9]{2}$ ]] && [[ "$2" =~ ^[0-9]{4}-?[0-9]{2}-?[0-9]{2}$ ]]; then
  start_date=$1
  stop_date=$2
  shift 2
  exec ./NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh "$0" "$start_date" "$stop_date" "$@"
fi

# Check if the correct number of arguments is provided
# Check the number of arguments
if [ "$#" -eq 0 ]; then
  # No arguments: run the PHP script without arguments
  php NashvilleMNPSDataWarehouseReport.php
elif [ "$#" -eq 1 ]; then
  # One argument: pass the argument to the PHP script
  php NashvilleMNPSDataWarehouseReport.php "$1"
else
  echo "Usage: $0 [start_date] [stop_date]"
  exit 1
fi

# Move the file to the destination directory
SOURCE_FILE="../data/LibraryServices-Checkouts-*"
DEST_DIR="/home/mnps.org/data/"

# Set file ownership and permissions
chown :mnps.org $SOURCE_FILE
chmod 660 $SOURCE_FILE

# Move the files
mv $SOURCE_FILE $DEST_DIR/