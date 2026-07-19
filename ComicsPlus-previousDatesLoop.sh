#!/bin/bash
# ComicsPlus-previousDatesLoop.sh
# James Staub, Nashville Public Library
# Wrapper for NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh
# This script processes ComicsPlus data for a range of dates.

if [ $# -lt 2 ]; then
  echo "Usage: $0 <start_date> <stop_date> [extra_args...]"
  echo "Example: $0 2026-05-01 2026-05-30"
  exit 1
fi

./NashvilleMNPSDataWarehouseReport-previousDatesLoop.sh ./NashvilleMNPSDataWarehouseReport-ComicsPlus.sh "$@"