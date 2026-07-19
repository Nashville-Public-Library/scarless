#!/bin/bash
# Mackin-previousDatesLoop.sh
# James Staub, Nashville Public Library
# Wrapper for NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh
# This script processes Mackin data for a range of dates.

if [ $# -lt 2 ]; then
  echo "Usage: $0 <start_date> <stop_date> [extra_args...]"
  echo "Example: $0 2025-03-01 2025-04-30"
  exit 1
fi

./NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh ./NashvilleMNPSDataWarehouseReport-Mackin.sh "$@"