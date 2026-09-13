#!/usr/bin/env python3
"""
MackinTitleMigration.py

Automates batch retrieval and transformation of title reports and subscription reports
from admin.mackinvia.com for MNPS schools and district-provided copies.

Usage:
    python MackinTitleMigration.py [options]

Options:
    --lookup=PATH                 Path to lookup file (default: mackin-lookup.txt)
    --data-dir=PATH               Output directory for data files (default: ../data)
    --config=PATH                 Path to config file (default: ../config.pwd.ini)
    --limit=N                     Process only first N schools (for testing)
    --school=CODE                 Process only specific school code(s) (comma-separated)
    --district                    Retrieve and transform district active titles report
    --district-only               Only retrieve and transform district active titles report
    --skip-download               Skip downloading reports and run combine/transform on existing files
    --clean-files                 Clean existing downloaded Excel files in data directory
    --output=PATH                 Path for final combined request list Excel file
    --special-output=PATH         Path for final Penguin Random House & Blackstone request list Excel file
    --district-output=PATH         Path for district copies transfer request list Excel file
    --district-special-output=PATH Path for district Penguin Random House & Blackstone request list Excel file
    --verbose                     Enable verbose debug logging
"""

import os
import sys
import json
import time
import re
import ssl
import csv
import io
import argparse
import configparser
import urllib.request
import urllib.parse
from typing import Dict, List, Any, Optional, Tuple
from collections import OrderedDict
from datetime import datetime
import openpyxl
from openpyxl import Workbook, load_workbook


class MackinTitleMigration:
    def __init__(self, config_path: str = '../config.pwd.ini', 
                 lookup_path: str = 'mackin-lookup.txt',
                 data_dir: str = '../data',
                 verbose: bool = False):
        self.config_path = config_path
        self.lookup_path = lookup_path
        self.data_dir = data_dir
        self.verbose = verbose
        
        self.sid: Optional[str] = None
        self.user_info: Dict[str, Any] = {}
        self.ctx = ssl.create_default_context()
        self.session_cookie = ""
        
        os.makedirs(self.data_dir, exist_ok=True)

    def log(self, msg: str):
        print(f"[{time.strftime('%Y-%m-%d %H:%M:%S')}] {msg}", flush=True)

    def debug(self, msg: str):
        if self.verbose:
            print(f"[DEBUG {time.strftime('%H:%M:%S')}] {msg}", flush=True)

    def get_credentials(self) -> Tuple[str, str]:
        if not os.path.exists(self.config_path):
            raise FileNotFoundError(f"Config file not found at {self.config_path}")
        
        config = configparser.ConfigParser(interpolation=None)
        config.read(self.config_path)
        
        username = ""
        password = ""
        
        if 'MackinVIA' in config:
            username = config['MackinVIA'].get('adminUser', '').strip(' "')
            password = config['MackinVIA'].get('adminPassword', '').strip(' "')
        elif 'Mackin' in config and 'adminUser' in config['Mackin']:
            username = config['Mackin']['adminUser'].strip(' "')
            password = config['Mackin']['adminPassword'].strip(' "')
        
        if not username or not password:
            # Credentials should be read from config file only
            # If config file is missing or incomplete, raise an error
            raise ValueError(
                "Credentials not found in config file. Please add [MackinVIA] section with "
                "adminUser and adminPassword to your config file at: " + self.config_path
            )
                
        return username, password

    def login(self):
        username, password = self.get_credentials()
        self.log(f"Logging in to admin.mackinvia.com as {username}...")
        
        login_url = "https://admin.mackinvia.com/api/public/admin/login"
        payload = json.dumps({"username": username, "password": password}).encode('utf-8')
        headers = {
            "Content-Type": "application/json",
            "Accept": "application/json, text/plain, */*",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)"
        }
        req = urllib.request.Request(login_url, data=payload, headers=headers, method="POST")
        with urllib.request.urlopen(req, context=self.ctx) as resp:
            data = json.loads(resp.read().decode('utf-8'))
            self.sid = data.get('sid')
            if not self.sid:
                raise RuntimeError(f"Login failed: No session ID returned. Response: {data}")
            self.debug(f"Received SID: {self.sid}")

        # Fetch administrators info / customer list
        admin_url = "https://admin.mackinvia.com/api/public/admin/administrators/me"
        admin_headers = {
            "Accept": "application/json, text/plain, */*",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
            "ASID": self.sid,
            "Cookie": f"ViaAdmin={self.sid}"
        }
        req = urllib.request.Request(admin_url, headers=admin_headers, method="GET")
        with urllib.request.urlopen(req, context=self.ctx) as resp:
            self.user_info = json.loads(resp.read().decode('utf-8'))
            customers = self.user_info.get('customers', [])
            self.log(f"Login successful. Authenticated user has access to {len(customers)} customer accounts.")

    def load_lookup(self) -> List[Dict[str, str]]:
        if not os.path.exists(self.lookup_path):
            raise FileNotFoundError(f"Lookup file not found at {self.lookup_path}")
        
        schools = []
        with open(self.lookup_path, 'r', encoding='utf-8') as f:
            lines = [line.strip().split('\t') for line in f if line.strip()]
        
        header = lines[0]
        for r in lines[1:]:
            if len(r) >= 4:
                schools.append({
                    'cust_code': r[0].strip(),
                    'school_code': r[1].strip(),
                    'mackin_code': r[2].strip(),
                    'name': r[3].strip()
                })
        self.log(f"Loaded {len(schools)} school mappings from {self.lookup_path}")
        return schools

    def get_purchasers(self, cust_id: int, account_id: int) -> List[Dict[str, Any]]:
        filter_url = f"https://admin.mackinvia.com/api/admin/accounts/{account_id}/purchaserId"
        headers = {
            "Accept": "application/json, text/plain, */*",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
            "ASID": self.sid,
            "Cookie": f"ViaAdmin={self.sid}; ViaAdminAccount={account_id}; ViaAdminCustomer={cust_id}"
        }
        req = urllib.request.Request(filter_url, headers=headers, method="GET")
        try:
            with urllib.request.urlopen(req, context=self.ctx) as resp:
                return json.loads(resp.read().decode('utf-8'))
        except Exception as e:
            self.debug(f"Could not retrieve purchasers for account {account_id}: {e}")
            return []

    def download_usage_report(self, cust_id: int, account_id: int, school_name: str) -> Optional[bytes]:
        purchasers = self.get_purchasers(cust_id, account_id)
        matching_purchaser_id = None
        for p in purchasers:
            p_name = p.get('name', '').strip().lower()
            if p_name == school_name.strip().lower():
                matching_purchaser_id = p.get('value')
                break
        
        # Fallback matching if exact match not found
        if not matching_purchaser_id and purchasers:
            for p in purchasers:
                p_name = p.get('name', '').strip().lower()
                # If purchaser is not the general district / promotions
                if "metropolitan nashville" not in p_name and "mackin promotions" not in p_name:
                    matching_purchaser_id = p.get('value')
                    break
            if not matching_purchaser_id:
                # Use school/account customer id
                matching_purchaser_id = purchasers[0].get('value')

        now_ms = int(time.time() * 1000)
        one_month_ago_ms = now_ms - (30 * 24 * 3600 * 1000)

        selected_columns = [
            "dateAdded", "active", "licenseType", "accessType", "resourceTypes", 
            "totalCopies", "providedBy", "title", "author", "publisher", "isbn"
        ]

        query_params = [
            ("scopeId", "2"),
            ("resourceTypes", "0"),
            ("dateAdded", str(one_month_ago_ms)),
            ("dateAdded", str(now_ms)),
            ("exportReport", "true"),
        ]
        if matching_purchaser_id is not None:
            query_params.append(("purchaserId", str(matching_purchaser_id)))
        for c in selected_columns:
            query_params.append(("selectedColumns", c))

        usage_url = f"https://admin.mackinvia.com/api/admin/accounts/{account_id}/resources/reports/usage?" + urllib.parse.urlencode(query_params)
        headers = {
            "Accept": "*/*",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
            "ASID": self.sid,
            "Cookie": f"ViaAdmin={self.sid}; ViaAdminAccount={account_id}; ViaAdminCustomer={cust_id}"
        }

        req = urllib.request.Request(usage_url, headers=headers, method="GET")
        try:
            with urllib.request.urlopen(req, context=self.ctx) as resp:
                return resp.read()
        except Exception as e:
            self.log(f"Error fetching usage report for cust {cust_id}, account {account_id} ({school_name}): {e}")
            return None

    def download_subscriptions_report(self, cust_id: int, account_id: int, school_name: str) -> Optional[bytes]:
        sub_url = f"https://admin.mackinvia.com/api/admin/customers/{cust_id}/accounts/{account_id}/subscriptions/report?status=CURRENT&exportReport=true"
        headers = {
            "Accept": "*/*",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
            "ASID": self.sid,
            "Cookie": f"ViaAdmin={self.sid}; ViaAdminAccount={account_id}; ViaAdminCustomer={cust_id}"
        }

        req = urllib.request.Request(sub_url, headers=headers, method="GET")
        try:
            with urllib.request.urlopen(req, context=self.ctx) as resp:
                return resp.read()
        except Exception as e:
            self.log(f"Error fetching subscription report for cust {cust_id}, account {account_id} ({school_name}): {e}")
            return None

    def download_district_usage_report(self, cust_id: int = 21507) -> Optional[bytes]:
        """Downloads Usage Report for District / Consortia account (e.g. METROPOLITAN NASHVILLE PUBLIC SCH)."""
        now_ms = int(time.time() * 1000)
        one_month_ago_ms = now_ms - (30 * 24 * 3600 * 1000)
        district_url = f"https://admin.mackinvia.com/api/admin/district/customers/{cust_id}/resources/reports/usage?dateAdded={one_month_ago_ms}&dateAdded={now_ms}&exportReport=true"
        headers = {
            "Accept": "*/*",
            "User-Agent": "Mozilla/5.0 (Windows NT 10.0; Win64; x64)",
            "ASID": self.sid,
            "Cookie": f"ViaAdmin={self.sid}; ViaAdminCustomer={cust_id}"
        }

        req = urllib.request.Request(district_url, headers=headers, method="GET")
        try:
            with urllib.request.urlopen(req, context=self.ctx) as resp:
                return resp.read()
        except Exception as e:
            self.log(f"Error fetching district usage report for customer {cust_id}: {e}")
            return None

    @staticmethod
    def _normalize_row(r: Any) -> Tuple[str, ...]:
        items = [str(c).strip() if c is not None else "" for c in r]
        while items and items[-1] == "":
            items.pop()
        return tuple(items)

    def extract_rows_from_excel_bytes(self, excel_bytes: bytes, report_type: str = 'titles') -> Tuple[List[str], List[List[Any]]]:
        import io
        wb = load_workbook(io.BytesIO(excel_bytes), data_only=True)
        sheet = wb.active
        
        all_rows = []
        for row in sheet.iter_rows(values_only=True):
            all_rows.append([str(c).strip() if c is not None else "" for c in row])
        
        header_row_idx = -1
        headers = []
        if report_type == 'titles':
            # Look for row containing 'Date Added', 'License Type', 'ISBN'
            for idx, r in enumerate(all_rows):
                r_upper = [c.upper() for c in r]
                if 'DATE ADDED' in r_upper and 'ISBN' in r_upper:
                    header_row_idx = idx
                    headers = r
                    break
        elif report_type == 'subscriptions':
            # Look for row containing 'Resource Type', 'Expires', 'ISBN'
            for idx, r in enumerate(all_rows):
                r_upper = [c.upper() for c in r]
                if 'EXPIRES' in r_upper and 'ISBN' in r_upper:
                    header_row_idx = idx
                    headers = r
                    break

        if header_row_idx == -1:
            return [], []

        # Clean trailing empty headers
        while headers and headers[-1] == "":
            headers.pop()

        data_rows = all_rows[header_row_idx + 1:]
        hdr_upper = [c.upper() for c in headers]

        # Filter out empty rows, EXPIRED statuses, pure METRO Provided By, and internal duplicates
        status_idx = hdr_upper.index('STATUS') if 'STATUS' in hdr_upper else -1
        prov_idx = hdr_upper.index('PROVIDED BY') if 'PROVIDED BY' in hdr_upper else -1

        filtered_rows = []
        seen = set()

        for row in data_rows:
            if not any(c != "" for c in row):
                continue

            # 1. Status checks for titles report
            if report_type == 'titles' and status_idx != -1 and len(row) > status_idx:
                status_val = str(row[status_idx]).strip().upper()
                if status_val not in ('ACTIVE', 'EXPIRED', ''):
                    self.log(f"Notice: Non-standard Status found in {report_type} report: '{row[status_idx]}'")
                if status_val == 'EXPIRED':
                    continue

            # 2. Exclude pure district copies where Provided By = METROPOLITAN NASHVILLE PUBLIC SCH
            if prov_idx != -1 and len(row) > prov_idx:
                prov_val = str(row[prov_idx]).strip().upper()
                if prov_val == 'METROPOLITAN NASHVILLE PUBLIC SCH':
                    continue

            trimmed_row = row[:len(headers)]
            norm_tuple = self._normalize_row(trimmed_row)
            if norm_tuple in seen:
                continue
            seen.add(norm_tuple)
            filtered_rows.append(trimmed_row)

        return headers, filtered_rows

    def save_or_append_excel(self, filepath: str, headers: List[str], new_rows: List[List[Any]]):
        clean_headers = [str(c).strip() if c is not None else "" for c in headers]
        while clean_headers and clean_headers[-1] in ("", "None"):
            clean_headers.pop()

        if not os.path.exists(filepath):
            wb = Workbook()
            ws = wb.active
            ws.append(clean_headers)
            seen = set()
            count = 0
            for r in new_rows:
                trimmed_r = list(r[:len(clean_headers)])
                norm_tuple = self._normalize_row(trimmed_r)
                if norm_tuple not in seen:
                    seen.add(norm_tuple)
                    ws.append(trimmed_r)
                    count += 1
            wb.save(filepath)
            self.debug(f"Created {filepath} with {count} rows")
        else:
            wb = load_workbook(filepath)
            ws = wb.active
            existing_rows = list(ws.iter_rows(values_only=True))
            seen = set()
            if existing_rows:
                for r in existing_rows[1:]:
                    seen.add(self._normalize_row(r))
            
            added_count = 0
            for r in new_rows:
                trimmed_r = list(r[:len(clean_headers)])
                norm_tuple = self._normalize_row(trimmed_r)
                if norm_tuple not in seen:
                    seen.add(norm_tuple)
                    ws.append(trimmed_r)
                    added_count += 1
            wb.save(filepath)
            self.debug(f"Appended {added_count} unique rows to existing {filepath}")

    def clean_data_files(self) -> Dict[str, Any]:
        """Cleans existing title and subscription files in data directory:
        - Removes duplicates
        - Excludes Status = EXPIRED
        - Excludes Provided By = METROPOLITAN NASHVILLE PUBLIC SCH
        - Reports unexpected statuses
        """
        title_files = sorted([f for f in os.listdir(self.data_dir) if f.startswith("mackin-migration-titles-") and f.endswith(".xlsx")])
        sub_files = sorted([f for f in os.listdir(self.data_dir) if f.startswith("mackin-migration-subscriptions-") and f.endswith(".xlsx")])
        
        self.log(f"Cleaning existing data files ({len(title_files)} title files, {len(sub_files)} subscription files)...")
        
        unexpected_statuses = set()
        titles_before_total = 0
        titles_after_total = 0
        subs_before_total = 0
        subs_after_total = 0

        # Clean title files
        for tf in title_files:
            tf_path = os.path.join(self.data_dir, tf)
            try:
                wb = load_workbook(tf_path, data_only=True)
                ws = wb.active
                rows = list(ws.iter_rows(values_only=True))
                if not rows:
                    continue
                headers = [str(c).strip() if c is not None else "" for c in rows[0]]
                while headers and headers[-1] in ("", "None"):
                    headers.pop()
                hdr_upper = [c.upper() for c in headers]
                status_idx = hdr_upper.index('STATUS') if 'STATUS' in hdr_upper else -1
                prov_idx = hdr_upper.index('PROVIDED BY') if 'PROVIDED BY' in hdr_upper else -1

                cleaned_rows = []
                seen = set()
                for r in rows[1:]:
                    titles_before_total += 1
                    if not any(r):
                        continue
                    
                    if status_idx != -1 and len(r) > status_idx and r[status_idx] is not None:
                        s_val = str(r[status_idx]).strip()
                        if s_val.upper() not in ('ACTIVE', 'EXPIRED', ''):
                            unexpected_statuses.add(s_val)
                        if s_val.upper() == 'EXPIRED':
                            continue

                    if prov_idx != -1 and len(r) > prov_idx and r[prov_idx] is not None:
                        p_val = str(r[prov_idx]).strip().upper()
                        if p_val == 'METROPOLITAN NASHVILLE PUBLIC SCH':
                            continue

                    trimmed_r = list(r[:len(headers)])
                    norm_tuple = self._normalize_row(trimmed_r)
                    if norm_tuple in seen:
                        continue
                    seen.add(norm_tuple)
                    cleaned_rows.append(trimmed_r)
                    titles_after_total += 1

                # Save cleaned file
                new_wb = Workbook()
                new_ws = new_wb.active
                new_ws.append(headers)
                for r in cleaned_rows:
                    new_ws.append(r)
                new_wb.save(tf_path)
            except Exception as e:
                self.log(f"Error cleaning title file {tf}: {e}")

        # Clean subscription files
        for sf in sub_files:
            sf_path = os.path.join(self.data_dir, sf)
            try:
                wb = load_workbook(sf_path, data_only=True)
                ws = wb.active
                rows = list(ws.iter_rows(values_only=True))
                if not rows:
                    continue
                headers = [str(c).strip() if c is not None else "" for c in rows[0]]
                while headers and headers[-1] in ("", "None"):
                    headers.pop()
                hdr_upper = [c.upper() for c in headers]
                prov_idx = hdr_upper.index('PROVIDED BY') if 'PROVIDED BY' in hdr_upper else -1

                cleaned_rows = []
                seen = set()
                for r in rows[1:]:
                    subs_before_total += 1
                    if not any(r):
                        continue

                    if prov_idx != -1 and len(r) > prov_idx and r[prov_idx] is not None:
                        p_val = str(r[prov_idx]).strip().upper()
                        if p_val == 'METROPOLITAN NASHVILLE PUBLIC SCH':
                            continue

                    trimmed_r = list(r[:len(headers)])
                    norm_tuple = self._normalize_row(trimmed_r)
                    if norm_tuple in seen:
                        continue
                    seen.add(norm_tuple)
                    cleaned_rows.append(trimmed_r)
                    subs_after_total += 1

                new_wb = Workbook()
                new_ws = new_wb.active
                new_ws.append(headers)
                for r in cleaned_rows:
                    new_ws.append(r)
                new_wb.save(sf_path)
            except Exception as e:
                self.log(f"Error cleaning subscription file {sf}: {e}")

        self.log(f"Data files cleaned:")
        self.log(f"  Title rows: {titles_before_total} -> {titles_after_total} (filtered duplicates, EXPIRED, pure METRO)")
        self.log(f"  Subscription rows: {subs_before_total} -> {subs_after_total} (filtered duplicates, pure METRO)")
        if unexpected_statuses:
            self.log(f"  Non-standard statuses identified outside (ACTIVE, EXPIRED): {sorted(unexpected_statuses)}")

        return {
            "titles_before": titles_before_total,
            "titles_after": titles_after_total,
            "subs_before": subs_before_total,
            "subs_after": subs_after_total,
            "unexpected_statuses": sorted(unexpected_statuses)
        }

    def fetch_all(self, target_schools: Optional[List[Dict[str, str]]] = None):
        if not self.sid:
            self.login()
        
        schools = target_schools if target_schools is not None else self.load_lookup()
        
        # Index customer objects from API
        api_customers_by_id = {str(c['id']): c for c in self.user_info.get('customers', [])}
        
        total = len(schools)
        self.log(f"Starting batch retrieval for {total} schools...")
        
        for idx, s in enumerate(schools, 1):
            cust_code = s['cust_code']
            school_code = s['school_code']
            school_name = s['name']
            
            c_obj = api_customers_by_id.get(cust_code)
            if not c_obj:
                # Try finding by name
                for c in self.user_info.get('customers', []):
                    if c['name'].strip().upper() == school_name.strip().upper():
                        c_obj = c
                        break
            
            if not c_obj or not c_obj.get('accounts'):
                self.log(f"[{idx}/{total}] Customer {cust_code} ({school_name}) has no active account in MackinVIA. Skipping download.")
                continue

            cust_id = c_obj['id']
            account_id = c_obj['accounts'][0]['id']
            account_name = c_obj['accounts'][0]['name']
            
            titles_file = os.path.join(self.data_dir, f"mackin-migration-titles-{school_code}.xlsx")
            subs_file = os.path.join(self.data_dir, f"mackin-migration-subscriptions-{school_code}.xlsx")
            
            self.log(f"[{idx}/{total}] Processing {school_name} (School Code: {school_code}, Cust: {cust_id}, Account: {account_id})...")
            
            # Step 1: Usage (Titles) Report
            usage_bytes = self.download_usage_report(cust_id, account_id, account_name)
            if usage_bytes:
                t_headers, t_rows = self.extract_rows_from_excel_bytes(usage_bytes, 'titles')
                if t_headers and t_rows:
                    self.save_or_append_excel(titles_file, t_headers, t_rows)
                    self.log(f"    Titles report saved: {len(t_rows)} titles -> {titles_file}")
                else:
                    self.debug(f"    Titles report empty for {school_name}")
            
            # Step 2: Subscription Report
            subs_bytes = self.download_subscriptions_report(cust_id, account_id, account_name)
            if subs_bytes:
                s_headers, s_rows = self.extract_rows_from_excel_bytes(subs_bytes, 'subscriptions')
                if s_headers and s_rows:
                    self.save_or_append_excel(subs_file, s_headers, s_rows)
                    self.log(f"    Subscriptions report saved: {len(s_rows)} subscriptions -> {subs_file}")
                else:
                    self.debug(f"    Subscriptions report empty for {school_name}")

        self.log("Batch retrieval completed.")

    @staticmethod
    def _parse_date(date_str: str) -> Optional[datetime]:
        if not date_str:
            return None
        date_str = str(date_str).strip()
        for fmt in ('%m/%d/%Y', '%Y-%m-%d', '%m/%d/%y', '%d/%m/%Y', '%Y/%m/%d'):
            try:
                return datetime.strptime(date_str, fmt)
            except ValueError:
                pass
        return None

    @classmethod
    def _get_earliest_date(cls, dates: List[str]) -> str:
        valid_dates = []
        for d in dates:
            if not d:
                continue
            dt = cls._parse_date(d)
            if dt:
                valid_dates.append((dt, d))
        if valid_dates:
            valid_dates.sort(key=lambda x: x[0])
            return valid_dates[0][1]
        for d in dates:
            if d:
                return d
        return ""

    @classmethod
    def _get_latest_date(cls, dates: List[str]) -> str:
        valid_dates = []
        for d in dates:
            if not d:
                continue
            dt = cls._parse_date(d)
            if dt:
                valid_dates.append((dt, d))
        if valid_dates:
            valid_dates.sort(key=lambda x: x[0], reverse=True)
            return valid_dates[0][1]
        for d in dates:
            if d:
                return d
        return ""

    def combine_and_transform(self, output_path: Optional[str] = None, special_output_path: Optional[str] = None) -> Tuple[str, str]:
        if not output_path:
            output_path = os.path.join(self.data_dir, "mackin-overdriveTitleTransferRequestList.xlsx")
        if not special_output_path:
            special_output_path = os.path.join(self.data_dir, "mackin-overdriveTitleTransferRequestList-PenguinRandomHouse-Blackstone.xlsx")

        self.log("Starting combining and transforming title and subscription reports...")
        
        # Discover all titles files in data directory
        title_files = [f for f in os.listdir(self.data_dir) if f.startswith("mackin-migration-titles-") and f.endswith(".xlsx")]
        self.log(f"Found {len(title_files)} school title files to combine.")

        target_headers = [
            'SCHOOL_CODE',
            'Current Vendor',
            'Title',
            'Author/Creator',
            '13-Digit ISBN',
            'Publisher',
            'Format (Must specify: ebook or audiobook)',
            'Lending Model (Must Specify:  One Copy/One User;  Metered by Checkout: 26;  Metered by Time: 12 months;  Metered by Checkout or Time: 52 or 24 months;  or Simultaneous Use)',
            'Total # Units transferring (Required for One Copy/One User and Metered by time  titles)',
            'Date First Unit Purchased',
            'Date expiring (Required for each Metered by Time and Simultaneous Use titles)',
            'Total Licenses/Checkouts used (Required for Metered by Checkout titles)',
            'Total Licenses/Checkouts remaining (Required for Metered by Checkout titles)'
        ]

        # Aggregate copies by (school_code, format, isbn_clean, lending_model)
        aggregated_records = OrderedDict()
        unexpected_statuses = set()

        for tf in sorted(title_files):
            m = re.match(r'mackin-migration-titles-(.+)\.xlsx', tf)
            if not m:
                continue
            school_code = m.group(1)
            titles_path = os.path.join(self.data_dir, tf)
            subs_path = os.path.join(self.data_dir, f"mackin-migration-subscriptions-{school_code}.xlsx")

            # Load all subscription records for this school
            subs_by_isbn = {}
            if os.path.exists(subs_path):
                try:
                    wb_sub = load_workbook(subs_path, data_only=True)
                    ws_sub = wb_sub.active
                    sub_rows = list(ws_sub.iter_rows(values_only=True))
                    if sub_rows:
                        s_hdr = [str(c).strip().upper() if c is not None else "" for c in sub_rows[0]]
                        lic_idx = s_hdr.index('LICENSE TYPE') if 'LICENSE TYPE' in s_hdr else -1
                        isbn_idx = s_hdr.index('ISBN') if 'ISBN' in s_hdr else -1
                        exp_idx = s_hdr.index('EXPIRES') if 'EXPIRES' in s_hdr else -1
                        prov_idx_s = s_hdr.index('PROVIDED BY') if 'PROVIDED BY' in s_hdr else -1
                        copies_idx_s = s_hdr.index('COPIES') if 'COPIES' in s_hdr else -1
                        date_idx_s = s_hdr.index('DATE ADDED') if 'DATE ADDED' in s_hdr else -1
                        status_idx_s = s_hdr.index('STATUS') if 'STATUS' in s_hdr else -1

                        seen_sub_tuples = set()
                        for sr in sub_rows[1:]:
                            if not any(sr):
                                continue
                            if prov_idx_s != -1 and len(sr) > prov_idx_s and sr[prov_idx_s] is not None:
                                if str(sr[prov_idx_s]).strip().upper() == 'METROPOLITAN NASHVILLE PUBLIC SCH':
                                    continue
                            if status_idx_s != -1 and len(sr) > status_idx_s and sr[status_idx_s] is not None:
                                if str(sr[status_idx_s]).strip().upper() == 'EXPIRED':
                                    continue
                            norm_sub = tuple(str(c).strip() if c is not None else "" for c in sr)
                            if norm_sub in seen_sub_tuples:
                                continue
                            seen_sub_tuples.add(norm_sub)

                            if isbn_idx != -1 and len(sr) > isbn_idx and sr[isbn_idx] is not None:
                                isbn_val = re.sub(r'[^0-9X]', '', str(sr[isbn_idx]).strip().upper())
                                if isbn_val:
                                    lic_val = str(sr[lic_idx]).strip().lower() if lic_idx != -1 and len(sr) > lic_idx and sr[lic_idx] is not None else ""
                                    exp_val = str(sr[exp_idx]).strip() if exp_idx != -1 and len(sr) > exp_idx and sr[exp_idx] is not None else ""
                                    c_val = str(sr[copies_idx_s]).strip() if copies_idx_s != -1 and len(sr) > copies_idx_s and sr[copies_idx_s] is not None else "1"
                                    cnt = int(c_val) if c_val.isdigit() and int(c_val) > 0 else 1
                                    d_val = str(sr[date_idx_s]).strip() if date_idx_s != -1 and len(sr) > date_idx_s and sr[date_idx_s] is not None else ""
                                    
                                    subs_by_isbn.setdefault(isbn_val, []).append({
                                        'lic': lic_val,
                                        'expires': exp_val,
                                        'copies': cnt,
                                        'date_added': d_val
                                    })
                except Exception as e:
                    self.log(f"Warning: Could not process subscriptions file {subs_path}: {e}")

            # Read titles file
            try:
                wb_t = load_workbook(titles_path, data_only=True)
                ws_t = wb_t.active
                t_rows = list(ws_t.iter_rows(values_only=True))
                if not t_rows:
                    continue
                
                t_hdr = [str(c).strip().upper() if c is not None else "" for c in t_rows[0]]
                
                def get_col_idx(names):
                    for name in names:
                        if name.upper() in t_hdr:
                            return t_hdr.index(name.upper())
                    return -1

                idx_date_added = get_col_idx(['Date Added'])
                idx_status = get_col_idx(['Status'])
                idx_license_type = get_col_idx(['License Type'])
                idx_access_type = get_col_idx(['Access Type'])
                idx_res_type = get_col_idx(['Resource Type'])
                idx_copies = get_col_idx(['Copies', 'Total Copies'])
                idx_provided_by = get_col_idx(['Provided By'])
                idx_title = get_col_idx(['Title'])
                idx_author = get_col_idx(['Author'])
                idx_publisher = get_col_idx(['Publisher'])
                idx_isbn = get_col_idx(['ISBN'])

                def val(row, idx_c):
                    if idx_c != -1 and idx_c < len(row) and row[idx_c] is not None:
                        return str(row[idx_c]).strip()
                    return ""

                seen_title_tuples = set()
                for r in t_rows[1:]:
                    if not any(r):
                        continue

                    # Deduplication of rows within school file
                    norm_row = tuple(str(c).strip() if c is not None else "" for c in r)
                    if norm_row in seen_title_tuples:
                        continue
                    seen_title_tuples.add(norm_row)

                    status = val(r, idx_status)
                    if status.upper() not in ('ACTIVE', 'EXPIRED', ''):
                        unexpected_statuses.add(status)
                    if status.upper() == 'EXPIRED':
                        continue

                    provided_by = val(r, idx_provided_by)
                    if provided_by.upper() == 'METROPOLITAN NASHVILLE PUBLIC SCH':
                        continue
                    
                    title = val(r, idx_title)
                    author = val(r, idx_author)
                    isbn = val(r, idx_isbn)
                    publisher = val(r, idx_publisher)
                    res_type = val(r, idx_res_type)
                    lic_type = val(r, idx_license_type)
                    acc_type = val(r, idx_access_type)
                    copies_str = val(r, idx_copies)
                    date_added = val(r, idx_date_added)
                    
                    # Clean ISBN
                    isbn_clean = re.sub(r'[^0-9X]', '', isbn.upper())
                    lic_clean = lic_type.lower()
                    fmt = "audiobook" if "audio" in res_type.lower() else "ebook"
                    title_copies = int(copies_str) if copies_str.isdigit() and int(copies_str) > 0 else 1

                    # Check if matching subscription records exist
                    matching_subs = subs_by_isbn.get(isbn_clean, [])

                    if matching_subs:
                        for sub in matching_subs:
                            s_exp = sub['expires']
                            s_cnt = sub['copies']
                            s_date = sub['date_added'] if sub['date_added'] else date_added

                            lending_model = "One Copy/One User"
                            units_transferring = 0
                            date_expiring = ""
                            checkouts_used = 0
                            checkouts_remaining = 0
                            is_metered_checkout = False
                            is_metered_time = False

                            metered_match = re.search(r'(\d+)/(\d+)\s*used', s_exp, re.I)
                            if metered_match:
                                is_metered_checkout = True
                                used_cnt = int(metered_match.group(1))
                                total_cnt = int(metered_match.group(2))
                                base_cap = total_cnt // s_cnt if s_cnt > 0 else total_cnt
                                lending_model = f"Metered by Checkout: {base_cap}"
                                checkouts_used = used_cnt
                                checkouts_remaining = max(0, total_cnt - used_cnt)
                            elif s_exp and any(c.isdigit() for c in s_exp) and ('/' in s_exp or '-' in s_exp):
                                is_metered_time = True
                                date_expiring = s_exp
                                if "simultaneous" in lic_clean or "simultaneous" in acc_type.lower():
                                    lending_model = "Simultaneous Use"
                                else:
                                    lending_model = "Metered by Time: 12 months"
                                units_transferring = s_cnt
                            elif "simultaneous" in lic_clean or "simultaneous" in acc_type.lower():
                                lending_model = "Simultaneous Use"
                                units_transferring = s_cnt
                            elif "subscription" in acc_type.lower():
                                is_metered_time = True
                                lending_model = "Metered by Time: 12 months"
                                units_transferring = s_cnt
                            else:
                                lending_model = "One Copy/One User"
                                units_transferring = s_cnt

                            agg_key = (school_code, fmt, isbn_clean, lending_model)
                            if agg_key not in aggregated_records:
                                aggregated_records[agg_key] = {
                                    'school_code': school_code,
                                    'current_vendor': "Mackin",
                                    'title': title,
                                    'author': author,
                                    'isbn': isbn_clean,
                                    'publisher': publisher,
                                    'format': fmt,
                                    'lending_model': lending_model,
                                    'units_transferring': units_transferring,
                                    'dates_purchased': [s_date] if s_date else [],
                                    'dates_expiring': [date_expiring] if date_expiring else [],
                                    'checkouts_used': checkouts_used if is_metered_checkout else None,
                                    'checkouts_remaining': checkouts_remaining if is_metered_checkout else None,
                                    'is_metered_checkout': is_metered_checkout
                                }
                            else:
                                rec = aggregated_records[agg_key]
                                if units_transferring:
                                    rec['units_transferring'] += units_transferring
                                if s_date:
                                    rec['dates_purchased'].append(s_date)
                                if date_expiring:
                                    rec['dates_expiring'].append(date_expiring)
                                if is_metered_checkout:
                                    if rec['checkouts_used'] is None:
                                        rec['checkouts_used'] = 0
                                        rec['checkouts_remaining'] = 0
                                    rec['checkouts_used'] += checkouts_used
                                    rec['checkouts_remaining'] += checkouts_remaining
                    else:
                        # No subscriptions (e.g. Perpetual)
                        lending_model = "One Copy/One User"
                        units_transferring = title_copies
                        date_expiring = ""
                        checkouts_used = 0
                        checkouts_remaining = 0
                        is_metered_checkout = False

                        if "simultaneous" in lic_clean or "simultaneous" in acc_type.lower():
                            lending_model = "Simultaneous Use"
                        elif "perpetual" in acc_type.lower() or "single" in lic_clean:
                            lending_model = "One Copy/One User"
                        elif "26 checkout" in acc_type.lower():
                            is_metered_checkout = True
                            lending_model = "Metered by Checkout: 26"
                            checkouts_used = 0
                            checkouts_remaining = 26 * title_copies
                        elif "25 checkout" in acc_type.lower():
                            is_metered_checkout = True
                            lending_model = "Metered by Checkout: 25"
                            checkouts_used = 0
                            checkouts_remaining = 25 * title_copies
                        elif "52 checkout" in acc_type.lower():
                            is_metered_checkout = True
                            lending_model = "Metered by Checkout: 52"
                            checkouts_used = 0
                            checkouts_remaining = 52 * title_copies
                        elif "subscription" in acc_type.lower():
                            lending_model = "Metered by Time: 12 months"

                        agg_key = (school_code, fmt, isbn_clean, lending_model)
                        if agg_key not in aggregated_records:
                            aggregated_records[agg_key] = {
                                'school_code': school_code,
                                'current_vendor': "Mackin",
                                'title': title,
                                'author': author,
                                'isbn': isbn_clean,
                                'publisher': publisher,
                                'format': fmt,
                                'lending_model': lending_model,
                                'units_transferring': units_transferring if not is_metered_checkout else 0,
                                'dates_purchased': [date_added] if date_added else [],
                                'dates_expiring': [date_expiring] if date_expiring else [],
                                'checkouts_used': checkouts_used if is_metered_checkout else None,
                                'checkouts_remaining': checkouts_remaining if is_metered_checkout else None,
                                'is_metered_checkout': is_metered_checkout
                            }
                        else:
                            rec = aggregated_records[agg_key]
                            if not is_metered_checkout:
                                rec['units_transferring'] += units_transferring
                            if date_added:
                                rec['dates_purchased'].append(date_added)
                            if date_expiring:
                                rec['dates_expiring'].append(date_expiring)
                            if is_metered_checkout:
                                if rec['checkouts_used'] is None:
                                    rec['checkouts_used'] = 0
                                    rec['checkouts_remaining'] = 0
                                rec['checkouts_used'] += checkouts_used
                                rec['checkouts_remaining'] += checkouts_remaining

            except Exception as e:
                self.log(f"Error processing titles file {titles_path}: {e}")

        if unexpected_statuses:
            self.log(f"Notice: Non-standard statuses outside (ACTIVE, EXPIRED) detected: {sorted(unexpected_statuses)}")

        # Build final rows
        all_combined_rows = []
        special_publisher_rows = []

        for rec in aggregated_records.values():
            date_first_purchased = self._get_earliest_date(rec['dates_purchased'])
            date_expiring = self._get_latest_date(rec['dates_expiring'])
            
            if rec['is_metered_checkout']:
                units_val = ""
                used_val = str(rec['checkouts_used']) if rec['checkouts_used'] is not None else ""
                rem_val = str(rec['checkouts_remaining']) if rec['checkouts_remaining'] is not None else ""
            else:
                units_val = str(rec['units_transferring']) if rec['units_transferring'] > 0 else "1"
                used_val = ""
                rem_val = ""

            row_record = [
                rec['school_code'],
                rec['current_vendor'],
                rec['title'],
                rec['author'],
                rec['isbn'],
                rec['publisher'],
                rec['format'],
                rec['lending_model'],
                units_val,
                date_first_purchased,
                date_expiring,
                used_val,
                rem_val
            ]
            all_combined_rows.append(row_record)

            if re.search(r'penguin|random\s*house|blackstone', rec['publisher'], re.I):
                special_publisher_rows.append(row_record)

        # Write main output workbook
        out_wb = Workbook()
        out_ws = out_wb.active
        out_ws.title = "Titles"
        out_ws.append(target_headers)
        for r in all_combined_rows:
            out_ws.append(r)
        out_wb.save(output_path)
        self.log(f"Saved combined and transformed transfer request list ({len(all_combined_rows)} records) to {output_path}")

        # Write special publisher output workbook
        spec_wb = Workbook()
        spec_ws = spec_wb.active
        spec_ws.title = "Titles"
        spec_ws.append(target_headers)
        for r in special_publisher_rows:
            spec_ws.append(r)
        spec_wb.save(special_output_path)
        self.log(f"Saved Penguin Random House & Blackstone transfer request list ({len(special_publisher_rows)} records) to {special_output_path}")

        return output_path, special_output_path

    def fetch_district(self, cust_id: int = 21507) -> Optional[str]:
        """Fetches district active titles usage report and saves it locally."""
        if not self.sid:
            self.login()
        
        self.log(f"Fetching District Usage Report for customer {cust_id} (METROPOLITAN NASHVILLE PUBLIC SCH)...")
        raw_bytes = self.download_district_usage_report(cust_id)
        if not raw_bytes:
            self.log(f"Error: Failed to download district usage report for customer {cust_id}.")
            return None

        # Save raw report to data directory
        district_csv_path = os.path.join(self.data_dir, "mackin-migration-district-usage.csv")
        with open(district_csv_path, "wb") as f:
            f.write(raw_bytes)
        self.log(f"Saved district usage report ({len(raw_bytes)} bytes) to {district_csv_path}")
        return district_csv_path

    def transform_district_report(self, district_csv_path: Optional[str] = None, 
                                  output_path: Optional[str] = None, 
                                  special_output_path: Optional[str] = None) -> Tuple[str, str]:
        """Transforms district usage report to match OverDrive Title Transfer Request format."""
        if not district_csv_path:
            district_csv_path = os.path.join(self.data_dir, "mackin-migration-district-usage.csv")
        if not output_path:
            output_path = os.path.join(self.data_dir, "mackin-overdriveTitleTransferRequestList-districtCopies.xlsx")
        if not special_output_path:
            special_output_path = os.path.join(self.data_dir, "mackin-overdriveTitleTransferRequestList-districtCopies-PenguinRandomHouse-Blackstone.xlsx")

        if not os.path.exists(district_csv_path):
            raise FileNotFoundError(f"District usage report not found at {district_csv_path}")

        self.log(f"Transforming district report from {district_csv_path}...")
        with open(district_csv_path, "r", encoding="utf-8-sig", errors="ignore") as f:
            reader = csv.reader(f)
            rows = list(reader)

        if not rows:
            self.log("District usage report is empty.")
            return output_path, special_output_path

        header = rows[0]
        hdr_upper = [str(c).strip().upper() for c in header]

        def get_col_idx(col_name: str) -> int:
            return hdr_upper.index(col_name.upper()) if col_name.upper() in hdr_upper else -1

        idx_date_added = get_col_idx('Date Added')
        idx_title = get_col_idx('Title')
        idx_author = get_col_idx('Author')
        idx_publisher = get_col_idx('Publisher')
        idx_isbn = get_col_idx('ISBN')
        idx_access_type = get_col_idx('Access Type')
        idx_status = get_col_idx('Subscription Status')
        idx_exp_date = get_col_idx('Subscription End Date')
        idx_res_type = get_col_idx('Resource Type')
        idx_lic_type = get_col_idx('License Type')
        idx_copies_avail = get_col_idx('Copies Available')
        idx_checkouts = get_col_idx('Checkouts')

        def clean_val(r: List[Any], idx: int) -> str:
            if idx != -1 and idx < len(r) and r[idx] is not None:
                v = str(r[idx]).strip()
                if v.startswith('="') and v.endswith('"'):
                    v = v[2:-1]
                elif v.startswith('='):
                    v = v[1:]
                return v
            return ""

        target_headers = [
            'SCHOOL_CODE',
            'Current Vendor',
            'Title',
            'Author/Creator',
            '13-Digit ISBN',
            'Publisher',
            'Format (Must specify: ebook or audiobook)',
            'Lending Model (Must Specify:  One Copy/One User;  Metered by Checkout: 26;  Metered by Time: 12 months;  Metered by Checkout or Time: 52 or 24 months;  or Simultaneous Use)',
            'Total # Units transferring (Required for One Copy/One User and Metered by time  titles)',
            'Date First Unit Purchased',
            'Date expiring (Required for each Metered by Time and Simultaneous Use titles)',
            'Total Licenses/Checkouts used (Required for Metered by Checkout titles)',
            'Total Licenses/Checkouts remaining (Required for Metered by Checkout titles)'
        ]

        all_district_rows = []
        special_publisher_rows = []

        for r in rows[1:]:
            if not any(r):
                continue
            status = clean_val(r, idx_status).upper()
            # District report denotes active copies with CURRENT status
            if status != 'CURRENT':
                continue

            title = clean_val(r, idx_title)
            author = clean_val(r, idx_author)
            isbn = re.sub(r'[^0-9X]', '', clean_val(r, idx_isbn).upper())
            publisher = clean_val(r, idx_publisher)
            res_type = clean_val(r, idx_res_type)
            lic_type = clean_val(r, idx_lic_type)
            access_type = clean_val(r, idx_access_type)
            copies_avail = clean_val(r, idx_copies_avail)
            date_added = clean_val(r, idx_date_added)
            exp_date = clean_val(r, idx_exp_date)
            checkouts = clean_val(r, idx_checkouts)

            current_vendor = "Mackin"

            # Format
            fmt = "ebook"
            if "audio" in res_type.lower():
                fmt = "audiobook"

            # Lending Model
            lending_model = "One Copy/One User"
            units_transferring = copies_avail if copies_avail else "1"
            date_expiring = ""
            checkouts_used = ""
            checkouts_remaining = ""

            if "26 checkouts" in access_type.lower():
                lending_model = "Metered by Checkout: 26"
                units_transferring = ""
                used_cnt = int(checkouts) if checkouts.isdigit() else 0
                rem_cnt = int(copies_avail) if copies_avail.isdigit() else 0
                checkouts_used = str(used_cnt)
                checkouts_remaining = str(rem_cnt)
            elif "52 checkouts" in access_type.lower():
                lending_model = "Metered by Checkout: 52"
                units_transferring = ""
                used_cnt = int(checkouts) if checkouts.isdigit() else 0
                rem_cnt = int(copies_avail) if copies_avail.isdigit() else 0
                checkouts_used = str(used_cnt)
                checkouts_remaining = str(rem_cnt)
            elif "subscription" in access_type.lower():
                if "simultaneous" in lic_type.lower() or "multi-user" in lic_type.lower():
                    lending_model = "Simultaneous Use"
                else:
                    lending_model = "Metered by Time: 12 months"
                units_transferring = copies_avail if copies_avail else "1"
                date_expiring = exp_date
            elif "simultaneous" in lic_type.lower() or "multi-user" in lic_type.lower():
                lending_model = "Simultaneous Use"
                units_transferring = copies_avail if copies_avail else "1"
                date_expiring = exp_date
            elif "perpetual" in access_type.lower():
                lending_model = "One Copy/One User"
                units_transferring = copies_avail if copies_avail else "1"

            date_purchased = date_added
            school_code = "DISTRICT"

            row_record = [
                school_code,
                current_vendor,
                title,
                author,
                isbn,
                publisher,
                fmt,
                lending_model,
                units_transferring,
                date_purchased,
                date_expiring,
                checkouts_used,
                checkouts_remaining
            ]
            all_district_rows.append(row_record)

            if re.search(r'penguin|random\s*house|blackstone', publisher, re.I):
                special_publisher_rows.append(row_record)

        # Write district main output workbook
        out_wb = Workbook()
        out_ws = out_wb.active
        out_ws.title = "Titles"
        out_ws.append(target_headers)
        for r in all_district_rows:
            out_ws.append(r)
        out_wb.save(output_path)
        self.log(f"Saved district copies transfer request list ({len(all_district_rows)} records) to {output_path}")

        # Write district special publisher output workbook
        spec_wb = Workbook()
        spec_ws = spec_wb.active
        spec_ws.title = "Titles"
        spec_ws.append(target_headers)
        for r in special_publisher_rows:
            spec_ws.append(r)
        spec_wb.save(special_output_path)
        self.log(f"Saved district Penguin Random House & Blackstone transfer request list ({len(special_publisher_rows)} records) to {special_output_path}")

        return output_path, special_output_path


def main():
    parser = argparse.ArgumentParser(description="MackinVIA Title Migration batch retrieval & transformation script")
    parser.add_argument("--lookup", default="mackin-lookup.txt", help="Path to mackin-lookup.txt")
    parser.add_argument("--data-dir", default="../data", help="Path to data output directory")
    parser.add_argument("--config", default="../config.pwd.ini", help="Path to config.pwd.ini")
    parser.add_argument("--limit", type=int, default=None, help="Process only first N schools")
    parser.add_argument("--school", type=str, default=None, help="Process only specific school code(s), comma-separated")
    parser.add_argument("--district", action="store_true", help="Retrieve and transform district active titles report")
    parser.add_argument("--district-only", action="store_true", help="Only retrieve and transform district active titles report")
    parser.add_argument("--skip-download", action="store_true", help="Skip downloading, run combine/transform on existing files")
    parser.add_argument("--clean-files", action="store_true", help="Clean existing downloaded Excel files in data directory")
    parser.add_argument("--output", default=None, help="Output path for final transformed xlsx")
    parser.add_argument("--special-output", default=None, help="Output path for Penguin Random House & Blackstone transformed xlsx")
    parser.add_argument("--district-output", default=None, help="Output path for district copies transformed xlsx")
    parser.add_argument("--district-special-output", default=None, help="Output path for district PRH & Blackstone transformed xlsx")
    parser.add_argument("--verbose", action="store_true", help="Enable verbose debug logging")

    args = parser.parse_args()

    app = MackinTitleMigration(
        config_path=args.config,
        lookup_path=args.lookup,
        data_dir=args.data_dir,
        verbose=args.verbose
    )

    if args.clean_files:
        app.clean_data_files()

    if args.district_only:
        if not args.skip_download:
            app.fetch_district()
        app.transform_district_report(
            output_path=args.district_output,
            special_output_path=args.district_special_output
        )
        return

    if not args.skip_download:
        schools = app.load_lookup()
        if args.school:
            target_codes = [c.strip() for c in args.school.split(",")]
            schools = [s for s in schools if s['school_code'] in target_codes]
        if args.limit:
            schools = schools[:args.limit]
        
        app.fetch_all(schools)
        
        # If full run or --district flag specified, also fetch district report
        if args.district or (not args.school and not args.limit):
            app.fetch_district()
    else:
        # If skip download, ensure existing data files are cleaned
        app.clean_data_files()

    # Combine & transform school files
    if not args.school:
        app.combine_and_transform(args.output, args.special_output)

    # Transform district report if district report exists or was requested
    district_csv_path = os.path.join(args.data_dir, "mackin-migration-district-usage.csv")
    if args.district or os.path.exists(district_csv_path):
        app.transform_district_report(
            district_csv_path=district_csv_path,
            output_path=args.district_output,
            special_output_path=args.district_special_output
        )


if __name__ == "__main__":
    main()
