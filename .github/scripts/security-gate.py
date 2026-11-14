#!/usr/bin/env python3
"""Fails a job of security.yml on high findings from ENFORCE_FROM on (Bible §13).

Usage: security-gate.py <count> "<what>"
Before ENFORCE_FROM (YYYY-MM-DD, UTC) it only prints a warning.
"""
import datetime
import os
import sys

count = int(sys.argv[1] or 0)
what = sys.argv[2] if len(sys.argv) > 2 else 'high finding(s)'
start = datetime.date.fromisoformat(os.environ.get('ENFORCE_FROM', '2999-01-01'))
today = datetime.datetime.now(datetime.timezone.utc).date()

if count == 0:
    print('No high findings.')
    sys.exit(0)
if today >= start:
    print(f'::error::{count} {what}. See the run summary.')
    sys.exit(1)
print(f'::warning::{count} {what}. Report only until {start}; from then this job fails.')
