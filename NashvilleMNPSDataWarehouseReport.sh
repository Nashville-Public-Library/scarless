#!/bin/bash
# NashvilleMNPSDataWarehouseReport.sh
# James Staub, Nashville Public Library
# This script runs the PHP script NashvilleMNPSDataWarehouseReport.php and then moves the files to the destination directory.

# Run the PHP script
php NashvilleMNPSDataWarehouseReport.php

# Move the file to the destination directory
DATESTRING=$(date -d "$(date +%Y-%m-15) -1 month" +%Y%m)
SOURCE_FILE="../data/Library Services - Checkouts Tangible - $DATESTRING.txt"
DEST_DIR="/home/mnps.org/data/"

# Move the files
mv $SOURCE_FILE $DEST_DIR/