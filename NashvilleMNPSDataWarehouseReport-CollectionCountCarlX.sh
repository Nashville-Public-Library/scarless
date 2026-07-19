#!/bin/bash
# NashvilleMNPSDataWarehouseReport-CollectionCountCarlX.sh
# This script runs the PHP script NashvilleMNPSDataWarehouseReport-CollectionCountCarlX.php and then moves the files to the destination directory.

php NashvilleMNPSDataWarehouseReport-CollectionCountCarlX.php

# Move the file to the destination directory
SOURCE_FILE="../data/LibraryServices-CollectionCountCarlX-MNPS-*"
DEST_DIR="/home/mnps.org/data/"

# Set file ownership and permissions
# Use sudo if necessary, but keep it consistent with the existing script
chown :mnps.org $SOURCE_FILE
chmod 660 $SOURCE_FILE

# Move the files
mv $SOURCE_FILE $DEST_DIR/
