#!/bin/bash
# NashvilleMNPSDataWarehouseReport.sh
# James Staub, Nashville Public Library
# This script runs the PHP script NashvilleMNPSDataWarehouseReport.php and then moves the files to the destination directory.

# Run the PHP script
php NashvilleMNPSDataWarehouseReport.php

# Move the file to the destination directory
SOURCE_FILE="../data/LibraryServices-Checkouts-*"
DEST_DIR="/home/mnps.org/data/"

# Set file ownership and permissions
chown :mnps.org $SOURCE_FILE
chmod 644 $SOURCE_FILE

# Move the files
mv $SOURCE_FILE $DEST_DIR/