#!/usr/bin/env Rscript
# invoke_hello_world_r.R -- R ETL example for ETL Control Panel SDK.
#
# Demonstrates the full control panel integration from R:
#   - Logs Started / Success / Failed via HTTP back to the control panel
#   - Supports --test-mode for safe non-destructive testing
#   - Simulates multi-step progress the UI tracks in real time
#
# Requirements:
#   install.packages("httr")  # for HTTP logging
#
# Usage:
#   Rscript invoke_hello_world_r.R
#   Rscript invoke_hello_world_r.R --test-mode
#   Rscript invoke_hello_world_r.R --log-url http://localhost:8080/log.php

# ── Parse args ────────────────────────────────────────────────────────────────
args         <- commandArgs(trailingOnly = TRUE)
test_mode    <- "--test-mode" %in% args
log_url      <- ""
process_name <- "Hello World R ETL"

for (i in seq_along(args)) {
  if (args[i] == "--log-url"          && i < length(args)) log_url      <- args[i + 1]
  if (args[i] == "--log-process-name" && i < length(args)) process_name <- args[i + 1]
}

start_time <- Sys.time()

# ── Helpers ───────────────────────────────────────────────────────────────────
log_msg <- function(message, level = "INFO") {
  ts <- format(Sys.time(), "%Y-%m-%d %H:%M:%S")
  cat(sprintf("[%s] [%s] %s\n", ts, level, message))
}

log_etl <- function(status, record_count = 0, error_message = NULL) {
  start_str <- format(start_time, "%Y-%m-%d %H:%M:%S")

  if (nchar(log_url) > 0) {
    # Local mode -- POST to log.php
    if (!requireNamespace("httr", quietly = TRUE)) {
      log_msg("httr not installed -- skipping HTTP log. Run: install.packages('httr')", "WARNING")
      return(invisible(NULL))
    }
    tryCatch({
      payload <- list(
        process_name = process_name,
        status       = status,
        record_count = as.character(record_count),
        start_time   = start_str
      )
      if (!is.null(error_message)) payload$error_message <- error_message
      httr::POST(log_url, body = payload, encode = "form")
      log_msg(sprintf("Logged '%s' to %s", status, log_url), "SUCCESS")
    }, error = function(e) {
      log_msg(sprintf("HTTP log failed (non-fatal): %s", e$message), "WARNING")
    })
    return(invisible(NULL))
  }

  # Production mode -- write to SQL Server via RODBC
  tryCatch({
    if (!requireNamespace("RODBC", quietly = TRUE)) stop("RODBC not installed")
    conn <- RODBC::odbcDriverConnect("Driver={ODBC Driver 17 for SQL Server};Server=localhost;Database=etl_control;Trusted_Connection=yes;")
    RODBC::sqlQuery(conn, sprintf(
      "INSERT INTO dbo.ETL_Sync_Log (Process_Name, Status, Record_Count, Start_Time, End_Time, Sync_Date)
       VALUES ('%s', '%s', %s, '%s', GETDATE(), GETDATE())",
      process_name, status,
      ifelse(record_count > 0, record_count, "NULL"),
      start_str
    ))
    RODBC::odbcClose(conn)
  }, error = function(e) {
    log_msg(sprintf("SQL log failed (non-fatal): %s", e$message), "WARNING")
  })
}

# ── Main ──────────────────────────────────────────────────────────────────────
mode_label <- if (test_mode) "[TEST MODE]" else ""
log_msg(sprintf("=== %s started %s ===", process_name, mode_label))

tryCatch({
  # Step 1 -- Initialize
  log_msg("Step 1/5: Initializing...")
  Sys.sleep(2)

  # Step 2 -- Generate sample data
  log_msg("Step 2/5: Generating sample data...")
  record_count <- if (test_mode) 10 else 100
  data <- data.frame(
    id    = 1:record_count,
    name  = paste("Record", 1:record_count),
    value = sample(1:1000, record_count, replace = TRUE)
  )
  log_msg(sprintf("Generated %d sample records", record_count), "SUCCESS")
  Sys.sleep(2)

  # Step 3 -- Process records
  log_msg("Step 3/5: Processing records...")
  processed <- data[data$value > 500, ]
  log_msg(sprintf("Processed %d records above threshold", nrow(processed)), "SUCCESS")
  Sys.sleep(2)

  # Step 4 -- Write output
  log_msg("Step 4/5: Writing output...")
  if (!test_mode) {
    out_path <- file.path(tempdir(), sprintf("HelloWorldR_%s.csv", format(Sys.time(), "%Y%m%d_%H%M%S")))
    write.csv(data, out_path, row.names = FALSE)
    log_msg(sprintf("Output written to: %s", out_path), "SUCCESS")
  } else {
    log_msg("Test mode -- skipping file write", "WARNING")
  }
  Sys.sleep(2)

  # Step 5 -- Complete
  log_msg("Step 5/5: Completing...")
  Sys.sleep(1)

  log_msg(sprintf("=== %s completed. Records: %d ===", process_name, record_count), "SUCCESS")
  log_etl("Success", record_count)

}, error = function(e) {
  log_msg(sprintf("=== %s FAILED: %s ===", process_name, e$message), "ERROR")
  log_etl("Failed", error_message = e$message)
  quit(status = 1)
})
