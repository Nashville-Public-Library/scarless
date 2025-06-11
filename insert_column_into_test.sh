#!/bin/bash
# insert_column_into_test.sh
# James Staub, Nashville Public Library

unique_branches=$(awk -F'|' '{print $20}' ../data/CARLX_INFINITECAMPUS_STUDENT.txt | sort | uniq | awk '{print "\""$0"\""}' | paste -sd, -)
input_file="../data/ic2carlx_mnps_students_test.txt"
output_file="../data/ic2carlx_mnps_students_test_with_branch.txt"

awk -F'|' -v OFS='|' -v branches="{$unique_branches}" '
  BEGIN {
    n = split(branches, arr, ",");
    for (i = 1; i <= n; i++) {
      gsub(/"/, "", arr[i]);
      b[arr[i]] = 1;
    }
  }
  {
    newcol = (b[$19]) ? $19 : "";
    for (i = 1; i <= 19; i++) printf "%s%s", $i, OFS;
    printf "%s", newcol;
    for (i = 20; i <= NF; i++) printf "%s%s", OFS, $i;
    print "";
  }
' "$input_file" > "$output_file"