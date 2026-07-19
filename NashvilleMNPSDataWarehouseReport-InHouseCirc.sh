#!/bin/bash
# NashvilleMNPSDataWarehouseReport-InHouseCirc.sh
# This script runs the PHP script NashvilleMNPSDataWarehouseReport-InHouseCirc.php and moves the files to the destination directory.

# Check if first two arguments are dates for a date range loop
if [[ $# -ge 2 ]] && [[ "$1" =~ ^[0-9]{4}-?[0-9]{2}-?[0-9]{2}$ ]] && [[ "$2" =~ ^[0-9]{4}-?[0-9]{2}-?[0-9]{2}$ ]]; then
  start_date=$1
  stop_date=$2
  shift 2
  exec ./NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh "$0" "$start_date" "$stop_date" "$@"
fi

# Check if an argument is provided
if [ "$#" -eq 0 ]; then
  # No arguments: run the PHP script without arguments
  php NashvilleMNPSDataWarehouseReport-InHouseCirc.php
elif [ "$#" -eq 1 ]; then
  # One argument: pass the argument to the PHP script
  php NashvilleMNPSDataWarehouseReport-InHouseCirc.php "$1"
else
  echo "Usage: $0 [start_date] [stop_date]"
  exit 1
fi

# Move the file to the destination directory
SOURCE_FILE="../data/LibraryServices-InHouseCirc-MNPS-*"
DEST_DIR="/home/mnps.org/data/"

# Set file ownership and permissions
chown :mnps.org $SOURCE_FILE
chmod 660 $SOURCE_FILE

# Move the files
mv $SOURCE_FILE $DEST_DIR/