#!/bin/bash
# NashvilleMNPSDataWarehouseReport-CollectionCountCarlX.sh
# This script runs the PHP script NashvilleMNPSDataWarehouseReport-CollectionCountCarlX.php and then moves the files to the destination directory.

# Check the number of arguments
if [ "$#" -eq 0 ]; then
  # No arguments: run the PHP script without arguments
  php NashvilleMNPSDataWarehouseReport-CollectionCountCarlX.php
else
  # Pass the argument to the PHP script (e.g., a specific date)
  php NashvilleMNPSDataWarehouseReport-CollectionCountCarlX.php "$1"
fi

# Move the file to the destination directory
SOURCE_FILE="../data/LibraryServices-CollectionCountCarlX-MNPS-*"
DEST_DIR="/home/mnps.org/data/"

# Set file ownership and permissions
# Use sudo if necessary, but keep it consistent with the existing script
chown :mnps.org $SOURCE_FILE
chmod 660 $SOURCE_FILE

# Move the files
mv $SOURCE_FILE $DEST_DIR/
