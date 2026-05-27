#!/usr/bin/env python3
"""
invoke_hello_world_py.py -- Python ETL example for ETL Control Panel SDK.

Demonstrates the full control panel integration from Python:
  - Logs Started / Success / Failed via HTTP back to the control panel
  - Supports --test-mode for safe non-destructive testing
  - Simulates multi-step progress the UI tracks in real time

Use this as a template when building Python ETL processes.

Usage:
    python invoke_hello_world_py.py
    python invoke_hello_world_py.py --test-mode
    python invoke_hello_world_py.py --log-url http://localhost:8080/log.php
"""

import argparse
import datetime
import random
import sys
import time

try:
    import requests
    HAS_REQUESTS = True
except ImportError:
    HAS_REQUESTS = False

# ── Args ──────────────────────────────────────────────────────────────────────
parser = argparse.ArgumentParser(description='Hello World Python ETL')
parser.add_argument('--test-mode',       action='store_true', help='Run in test mode (no writes)')
parser.add_argument('--log-url',         default='',          help='ETL Control Panel log endpoint')
parser.add_argument('--log-process-name',default='Hello World Python ETL', help='Process name for ETL_Sync_Log')
parser.add_argument('--sql-server',      default='localhost',  help='SQL Server (production mode)')
parser.add_argument('--database',        default='etl_control',help='Database (production mode)')
args = parser.parse_args()

PROCESS_NAME = args.log_process_name
START_TIME   = datetime.datetime.now()

# ── Logging helpers ───────────────────────────────────────────────────────────
def log(message, level='INFO'):
    colors = {'SUCCESS': '\033[92m', 'WARNING': '\033[93m', 'ERROR': '\033[91m', 'INFO': '\033[0m'}
    reset  = '\033[0m'
    ts     = datetime.datetime.now().strftime('%Y-%m-%d %H:%M:%S')
    print(f"{colors.get(level, '')}[{ts}] [{level}] {message}{reset}")

def log_etl(status, record_count=0, error_message=None):
    start_str = START_TIME.strftime('%Y-%m-%d %H:%M:%S')

    if args.log_url:
        # Local mode -- POST to log.php
        if not HAS_REQUESTS:
            log('requests not installed -- skipping HTTP log', 'WARNING')
            return
        try:
            payload = {
                'process_name': PROCESS_NAME,
                'status':       status,
                'record_count': record_count,
                'start_time':   start_str,
            }
            if error_message:
                payload['error_message'] = error_message
            r = requests.post(args.log_url, data=payload, timeout=10)
            r.raise_for_status()
            log(f"Logged '{status}' to {args.log_url}", 'SUCCESS')
        except Exception as e:
            log(f"HTTP log failed (non-fatal): {e}", 'WARNING')
        return

    # Production mode -- write directly to SQL Server
    try:
        import pyodbc
        conn = pyodbc.connect(
            f'DRIVER={{ODBC Driver 17 for SQL Server}};'
            f'SERVER={args.sql_server};DATABASE={args.database};'
            f'Trusted_Connection=yes;TrustServerCertificate=yes;'
        )
        cursor = conn.cursor()
        cursor.execute(
            "INSERT INTO dbo.ETL_Sync_Log "
            "(Process_Name, Status, Record_Count, Error_Message, Start_Time, End_Time, Sync_Date) "
            "VALUES (?, ?, ?, ?, ?, GETDATE(), GETDATE())",
            PROCESS_NAME, status,
            record_count if record_count else None,
            error_message,
            START_TIME
        )
        conn.commit()
        conn.close()
    except Exception as e:
        log(f"SQL log failed (non-fatal): {e}", 'WARNING')

# ── Main ──────────────────────────────────────────────────────────────────────
def main():
    mode = '[TEST MODE]' if args.test_mode else ''
    log(f"=== {PROCESS_NAME} started {mode} ===")

    try:
        # Step 1 -- Initialize
        log('Step 1/5: Initializing...')
        time.sleep(2)

        # Step 2 -- Generate sample data
        log('Step 2/5: Generating sample data...')
        record_count = 10 if args.test_mode else 100
        data = [
            {'id': i, 'name': f'Record {i}', 'value': random.randint(1, 1000)}
            for i in range(1, record_count + 1)
        ]
        log(f"Generated {record_count} sample records", 'SUCCESS')
        time.sleep(2)

        # Step 3 -- Process records
        log('Step 3/5: Processing records...')
        processed = [r for r in data if r['value'] > 500]
        log(f"Processed {len(processed)} records above threshold", 'SUCCESS')
        time.sleep(2)

        # Step 4 -- Write output
        log('Step 4/5: Writing output...')
        if not args.test_mode:
            import tempfile, csv, os
            out_path = os.path.join(tempfile.gettempdir(), f'HelloWorldPy_{datetime.datetime.now().strftime("%Y%m%d_%H%M%S")}.csv')
            with open(out_path, 'w', newline='') as f:
                writer = csv.DictWriter(f, fieldnames=['id', 'name', 'value'])
                writer.writeheader()
                writer.writerows(data)
            log(f"Output written to: {out_path}", 'SUCCESS')
        else:
            log('Test mode -- skipping file write', 'WARNING')
        time.sleep(2)

        # Step 5 -- Complete
        log('Step 5/5: Completing...')
        time.sleep(1)

        log(f"=== {PROCESS_NAME} completed. Records: {record_count} ===", 'SUCCESS')
        log_etl('Success', record_count)

    except Exception as e:
        log(f"=== {PROCESS_NAME} FAILED: {e} ===", 'ERROR')
        log_etl('Failed', error_message=str(e))
        sys.exit(1)

if __name__ == '__main__':
    main()
