#!/usr/bin/env node
// invoke_hello_world_node.js -- Node.js ETL example for ETL Control Panel SDK.
//
// Demonstrates the full control panel integration from Node.js:
//   - Logs Started / Success / Failed via HTTP back to the control panel
//   - Supports --test-mode for safe non-destructive testing
//   - Simulates multi-step progress the UI tracks in real time
//   - Uses only Node.js built-ins (no npm packages needed for HTTP logging)
//
// Usage:
//   node invoke_hello_world_node.js
//   node invoke_hello_world_node.js --test-mode
//   node invoke_hello_world_node.js --log-url http://localhost:8080/log.php

'use strict';

const http = require('http');
const https = require('https');
const fs = require('fs');
const path = require('path');
const os = require('os');

// ── Parse args ────────────────────────────────────────────────────────────────
const args = process.argv.slice(2);
const testMode    = args.includes('--test-mode');
let   logUrl      = '';
let   processName = 'Hello World Node ETL';

for (let i = 0; i < args.length; i++) {
    if (args[i] === '--log-url'          && args[i + 1]) logUrl      = args[i + 1];
    if (args[i] === '--log-process-name' && args[i + 1]) processName = args[i + 1];
}

const startTime = new Date();
// Format as local time string (no timezone suffix) to match PHP/Python/R/PS format
function fmtLocal(d) {
    const pad = n => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
}

// ── Helpers ───────────────────────────────────────────────────────────────────
function logMsg(message, level = 'INFO') {
    const ts = new Date().toISOString().replace('T', ' ').substring(0, 19);
    console.log(`[${ts}] [${level}] ${message}`);
}

function sleep(ms) {
    return new Promise(resolve => setTimeout(resolve, ms));
}

function postForm(url, data) {
    return new Promise((resolve, reject) => {
        const body = Object.entries(data)
            .map(([k, v]) => `${encodeURIComponent(k)}=${encodeURIComponent(v ?? '')}`)
            .join('&');
        const parsed   = new URL(url);
        const lib      = parsed.protocol === 'https:' ? https : http;
        const options  = {
            hostname: parsed.hostname,
            port:     parsed.port || (parsed.protocol === 'https:' ? 443 : 80),
            path:     parsed.pathname + parsed.search,
            method:   'POST',
            headers:  {
                'Content-Type':   'application/x-www-form-urlencoded',
                'Content-Length': Buffer.byteLength(body),
            },
        };
        const req = lib.request(options, res => {
            let data = '';
            res.on('data', chunk => data += chunk);
            res.on('end', () => resolve(data));
        });
        req.on('error', reject);
        req.write(body);
        req.end();
    });
}

async function logEtl(status, recordCount = 0, errorMessage = null) {
    const startStr = fmtLocal(startTime);

    if (logUrl) {
        // Local mode -- POST to log.php
        try {
            const payload = {
                process_name: processName,
                status,
                record_count: recordCount,
                start_time:   startStr,
            };
            if (errorMessage) payload.error_message = errorMessage;
            await postForm(logUrl, payload);
            logMsg(`Logged '${status}' to ${logUrl}`, 'SUCCESS');
        } catch (e) {
            logMsg(`HTTP log failed (non-fatal): ${e.message}`, 'WARNING');
        }
        return;
    }

    // Production mode -- use mssql or tedious package if available
    try {
        const sql = require('mssql');
        const pool = await sql.connect('Server=localhost;Database=etl_control;Trusted_Connection=True;');
        await pool.request()
            .input('name',   sql.NVarChar, processName)
            .input('status', sql.NVarChar, status)
            .input('count',  sql.Int,      recordCount || null)
            .input('err',    sql.NVarChar, errorMessage || null)
            .input('start',  sql.DateTime, startTime)
            .query("INSERT INTO dbo.ETL_Sync_Log (Process_Name,Status,Record_Count,Error_Message,Start_Time,End_Time,Sync_Date) VALUES (@name,@status,@count,@err,@start,GETDATE(),GETDATE())");
        await sql.close();
    } catch (e) {
        logMsg(`SQL log failed (non-fatal): ${e.message}`, 'WARNING');
    }
}

// ── Main ──────────────────────────────────────────────────────────────────────
async function main() {
    const modeLabel = testMode ? '[TEST MODE]' : '';
    logMsg(`=== ${processName} started ${modeLabel} ===`);

    try {
        // Step 1 -- Initialize
        logMsg('Step 1/5: Initializing...');
        await sleep(2000);

        // Step 2 -- Generate sample data
        logMsg('Step 2/5: Generating sample data...');
        const recordCount = testMode ? 10 : 100;
        const data = Array.from({ length: recordCount }, (_, i) => ({
            id:    i + 1,
            name:  `Record ${i + 1}`,
            value: Math.floor(Math.random() * 1000) + 1,
        }));
        logMsg(`Generated ${recordCount} sample records`, 'SUCCESS');
        await sleep(2000);

        // Step 3 -- Process records
        logMsg('Step 3/5: Processing records...');
        const processed = data.filter(r => r.value > 500);
        logMsg(`Processed ${processed.length} records above threshold`, 'SUCCESS');
        await sleep(2000);

        // Step 4 -- Write output
        logMsg('Step 4/5: Writing output...');
        if (!testMode) {
            const ts      = new Date().toISOString().replace(/[:.]/g, '').substring(0, 15);
            const outPath = path.join(os.tmpdir(), `HelloWorldNode_${ts}.json`);
            fs.writeFileSync(outPath, JSON.stringify(data, null, 2));
            logMsg(`Output written to: ${outPath}`, 'SUCCESS');
        } else {
            logMsg('Test mode -- skipping file write', 'WARNING');
        }
        await sleep(1000);

        // Step 5 -- Complete
        logMsg('Step 5/5: Completing...');
        await sleep(1000);

        logMsg(`=== ${processName} completed. Records: ${recordCount} ===`, 'SUCCESS');
        await logEtl('Success', recordCount);

    } catch (e) {
        logMsg(`=== ${processName} FAILED: ${e.message} ===`, 'ERROR');
        await logEtl('Failed', 0, e.message);
        process.exit(1);
    }
}

main();
