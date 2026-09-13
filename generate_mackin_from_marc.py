#!/usr/bin/env python3
"""
generate_mackin_from_marc.py

Extracts bibliographic and holding data from MARC files:
  - ../data/mackinvia-ya.mrc
  - ../data/mackinvia.mrc
  - ../data/MackinVIA-freeEbooks-20210203.mrc

Generates ../data/mackin-fromMARC.xlsx with columns:
  - Current Vendor
  - Title
  - Author/Creator
  - 13-Digit ISBN
  - Publisher
  - Format
  - school code
"""

import os
import re
import pymarc
import openpyxl


def extract_title(record: pymarc.Record) -> str:
    f245 = record.get('245')
    if not f245:
        return ''
    title = f245.get('a', '').strip()
    title = re.sub(r'[\s/=;:]+$', '', title).strip()

    b = f245.get('b', '').strip()
    if b:
        b = re.sub(r'[\s/=;:]+$', '', b).strip()
        if b:
            if title:
                title = f"{title} : {b}"
            else:
                title = b

    n = f245.get('n', '').strip()
    if n:
        n = re.sub(r'[\s/=;:]+$', '', n).strip()
        if n:
            title = f"{title}. {n}"

    p = f245.get('p', '').strip()
    if p:
        p = re.sub(r'[\s/=;:]+$', '', p).strip()
        if p:
            title = f"{title}. {p}"

    title = re.sub(r'[\s/=;:]+$', '', title).strip()
    return title


def extract_author(record: pymarc.Record) -> str:
    for tag in ('100', '110', '111', '700'):
        f = record.get(tag)
        if f:
            a = f.get('a', '').strip()
            a = re.sub(r'[\s,;:=]+$', '', a).strip()
            if a:
                return a
    return ''


def extract_isbn(record: pymarc.Record) -> str:
    for f in record.get_fields('020'):
        for a in f.get_subfields('a'):
            m = re.search(r'(97[89]\d{10})', a)
            if m:
                return m.group(1)
            m13 = re.search(r'(\d{13})', a)
            if m13:
                return m13.group(1)
    return ''


def extract_publisher(record: pymarc.Record) -> str:
    for tag in ('264', '260'):
        for f in record.get_fields(tag):
            b = f.get('b', '').strip()
            if b:
                b = re.sub(r'[\s,;:=]+$', '', b).strip()
                return b
    return ''


def extract_format(record: pymarc.Record) -> str:
    # 590 local note
    for f in record.get_fields('590'):
        v = f.value().lower()
        if 'audiobook' in v or 'audio' in v or 'sound recording' in v:
            return 'audiobook'
        if 'ebook' in v or 'e-book' in v or 'electronic book' in v:
            return 'ebook'

    # 949 check
    for f in record.get_fields('949'):
        v = f.value().lower()
        if 'audio' in v:
            return 'audiobook'
        if 'ebook' in v:
            return 'ebook'

    # 300 check
    f300 = record.get('300')
    if f300:
        v = f300.value().lower()
        if 'audiobook' in v or 'audio' in v or 'sound recording' in v or 'spoken word' in v:
            return 'audiobook'

    # Leader / 336
    for f in record.get_fields('336'):
        if 'spoken word' in f.value().lower():
            return 'audiobook'

    lead = str(record.leader) if record.leader else ''
    if len(lead) > 6 and lead[6] == 'i':
        return 'audiobook'

    return 'ebook'


def extract_school_code(h: str) -> str:
    h = h.strip()
    m = re.match(r'^.{2}(\d{3})$', h)
    if m:
        return m.group(1)
    return h


def process_marc_files(input_files: list, output_xlsx: str):
    wb = openpyxl.Workbook()
    ws = wb.active
    ws.title = 'Sheet1'

    headers = [
        'Current Vendor',
        'Title',
        'Author/Creator',
        '13-Digit ISBN',
        'Publisher',
        'Format',
        'school code'
    ]
    ws.append(headers)

    total_records = 0
    total_rows = 0

    for file_path in input_files:
        if not os.path.exists(file_path):
            print(f"Warning: File not found: {file_path}")
            continue

        print(f"Processing {file_path}...")
        file_records = 0
        file_rows = 0

        with open(file_path, 'rb') as f:
            reader = pymarc.MARCReader(
                f,
                to_unicode=True,
                utf8_handling='replace',
                hide_utf8_warnings=True
            )
            for record in reader:
                if record is None:
                    continue

                file_records += 1
                total_records += 1

                vendor = 'Mackin'
                title = extract_title(record)
                author = extract_author(record)
                isbn = extract_isbn(record)
                publisher = extract_publisher(record)
                fmt = extract_format(record)

                # Collect all 949$h values
                holdings = []
                for f949 in record.get_fields('949'):
                    for h in f949.get_subfields('h'):
                        holdings.append(h)

                if holdings:
                    for h in holdings:
                        sc = extract_school_code(h)
                        ws.append([vendor, title, author, isbn, publisher, fmt, sc])
                        file_rows += 1
                        total_rows += 1
                else:
                    # No 949$h in record, output row with empty school code
                    ws.append([vendor, title, author, isbn, publisher, fmt, ''])
                    file_rows += 1
                    total_rows += 1

        print(f"  Processed {file_records} records, generated {file_rows} rows.")

    os.makedirs(os.path.dirname(os.path.abspath(output_xlsx)), exist_ok=True)
    wb.save(output_xlsx)
    print(f"Saved {total_rows} rows from {total_records} records to {output_xlsx}")


if __name__ == '__main__':
    marc_files = [
        '../data/mackinvia-ya.mrc',
        '../data/mackinvia.mrc',
        '../data/MackinVIA-freeEbooks-20210203.mrc'
    ]
    out_file = '../data/mackin-fromMARC.xlsx'
    process_marc_files(marc_files, out_file)
