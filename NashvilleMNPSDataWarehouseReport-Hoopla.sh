#!/bin/bash
# NashvilleMNPSDataWarehouseReport-Hoopla.sh
# James Staub, Nashville Public Library with assistance from JetBrains Junie
#
# USAGE:
# ./NashvilleMNPSDataWarehouseReport-Hoopla.sh [date] [-localfile] [-verbose] [-no-email]
# ./NashvilleMNPSDataWarehouseReport-Hoopla.sh [start_date] [stop_date] [-localfile] [-verbose] [-no-email]
#
# DESCRIPTION:
#   This script retrieves Hoopla Overall Circulations reports from Midwest Tape.
#
#   If two dates are provided, it loops through the date range using
#   NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh.

# Check if first two arguments are dates for a date range loop
if [[ $# -ge 2 ]] && [[ "$1" =~ ^[0-9]{4}-?[0-9]{2}-?[0-9]{2}$ ]] && [[ "$2" =~ ^[0-9]{4}-?[0-9]{2}-?[0-9]{2}$ ]]; then
    start_date=$1
    stop_date=$2
    shift 2
    exec ./NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh "$0" "$start_date" "$stop_date" "$@"
fi

# Read the configuration file
MNPSEmailRecipients=$(awk -F "=" '/NashvilleMNPS/ {print $2}' ../config.pwd.ini | tr -d '[:space:]' | sed 's/^"\(.*\)"$/\1/')

# Initialize flags
use_local=false
verbose=false
no_email=false

# Simple argument parsing
for arg in "$@"; do
    if [[ "$arg" == "-localfile" ]]; then
        use_local=true
    elif [[ "$arg" == "-verbose" ]]; then
        verbose=true
    elif [[ "$arg" == "-no-email" ]]; then
        no_email=true
    elif [[ "$arg" =~ ^[0-9]{4}-?[0-9]{2}-?[0-9]{2}$ ]]; then
        date_str="$arg"
    fi
done

# Function to send error emails
send_error_email() {
    local error_message="$1"
    local subject="MNPS Hoopla Report Error: $error_message"
    echo "$error_message"
    if [ "$no_email" = true ]; then
        return
    fi
    if [ -n "$MNPSEmailRecipients" ]; then
        echo "$error_message" | mail -s "$subject" "$MNPSEmailRecipients"
    fi
}

# Determine the date
if [ -n "$date_str" ]; then
    date=$(date -d "$date_str" +'%Y-%m-%d' 2>/dev/null)
    if [ $? -ne 0 ]; then
        send_error_email "Invalid date format: $date_str. Use YYYY-MM-DD."
        exit 1
    fi
else
    date=$(date -d "yesterday" +'%Y-%m-%d')
fi

# Format dates
date_safe=$(date -d "$date" +'%Y%m%d')
date_output=$(date -d "$date" +'%Y-%m-%d')

# Ensure data directory exists
mkdir -p ../data/hoopla/

REPORT_FILE="../data/hoopla/Hoopla_Report_$date_safe.csv"

if [ "$use_local" = true ]; then
    echo "Using local file: $REPORT_FILE"
    if [ ! -f "$REPORT_FILE" ]; then
        send_error_email "Local file not found: $REPORT_FILE"
        exit 1
    fi
else
    echo "Downloading Hoopla report for $date..."
    PHP_OPTS=""
    if [ "$verbose" = true ]; then PHP_OPTS="$PHP_OPTS -verbose"; fi
    
    php NashvilleMNPSDataWarehouseReport-Hoopla.php "$date" $PHP_OPTS
    if [ $? -ne 0 ]; then
        send_error_email "Failed to download Hoopla report for $date."
        exit 1
    fi
fi

echo "Hoopla report process for $date completed."
