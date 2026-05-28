# ODBC Type Mapping — Future Implementation Reference

## Current behavior (as of May 2026)
All values cast to `[string]` before binding. Works for most ETL scenarios
since SQL Server/MySQL coerce strings silently. PostgreSQL is stricter but
accepts strings for standard scalar types.

## Proper implementation

Replace the `$val` line and `Parameters.AddWithValue` in both `mysql` and
`postgres` dest blocks in `generate_script.php` with a typed parameter approach.

### PowerShell helper function to add to the generated script header

Add this to the heredoc in `generatePowershell()` alongside `Write-Log` etc:

```powershell
function Add-TypedParameter {
    param(
        [System.Data.Odbc.OdbcCommand]$Cmd,
        [string]$Name,
        [object]$Value,
        [System.Type]$DotNetType
    )
    $typeMap = @{
        'System.Boolean'  = [System.Data.Odbc.OdbcType]::Bit
        'System.Byte'     = [System.Data.Odbc.OdbcType]::TinyInt
        'System.Int16'    = [System.Data.Odbc.OdbcType]::SmallInt
        'System.Int32'    = [System.Data.Odbc.OdbcType]::Int
        'System.Int64'    = [System.Data.Odbc.OdbcType]::BigInt
        'System.Single'   = [System.Data.Odbc.OdbcType]::Real
        'System.Double'   = [System.Data.Odbc.OdbcType]::Double
        'System.Decimal'  = [System.Data.Odbc.OdbcType]::Decimal
        'System.DateTime' = [System.Data.Odbc.OdbcType]::DateTime
        'System.Guid'     = [System.Data.Odbc.OdbcType]::UniqueIdentifier
        'System.Byte[]'   = [System.Data.Odbc.OdbcType]::Binary
        'System.String'   = [System.Data.Odbc.OdbcType]::NVarChar
    }
    $odbcType = if ($typeMap.ContainsKey($DotNetType.FullName)) {
        $typeMap[$DotNetType.FullName]
    } else {
        [System.Data.Odbc.OdbcType]::NVarChar  # safe fallback
    }
    $param = $Cmd.Parameters.Add($Name, $odbcType)
    $param.Value = if ($Value -is [DBNull] -or $null -eq $Value) {
        [DBNull]::Value
    } else {
        $Value
    }
}
```

### Updated inner loop (replaces current foreach $col block)

```powershell
foreach ($col in $srcData.Columns) {
    $val = $row[$col.ColumnName]
    Add-TypedParameter -Cmd $dstCmd -Name $col.ColumnName -Value $val -DotNetType $col.DataType
}
```

### Why this is better

| Scenario | String cast | Typed |
|---|---|---|
| INT column | Works (coerced) | Correct |
| DECIMAL precision | May lose precision | Exact |
| DATETIME | Works on SQL Server, breaks on strict PG | Correct |
| BOOLEAN | Works (0/1 as string) | Correct |
| BYTEA / BLOB | Breaks | Correct |
| NULL | Works with [DBNull]::Value | Correct |
| JSON / ARRAY columns | Breaks either way — needs special handling | N/A |

### Notes

- JSON and PostgreSQL ARRAY columns need per-column special handling regardless
  of this approach — there's no generic fix for those.
- The `UniqueIdentifier` OdbcType may not be supported by all ODBC drivers —
  fall back to `NVarChar` for GUID columns if you hit driver errors.
- For SQL Server destinations, `SqlBulkCopy` already handles type mapping
  automatically by matching column ordinals — no changes needed there.
- This function only applies to ODBC destinations (mysql, postgres).
  SQL Server destination uses SqlBulkCopy which is already typed correctly.

## Implementation steps when ready

1. Add `Add-TypedParameter` function to the heredoc in `generatePowershell()`
2. Replace the `foreach ($col in $srcData.Columns)` inner loop in both
   `mysql` and `postgres` dest blocks with the typed version above
3. Remove the `[string]` cast from the `$val` line (no longer needed)
4. Test with: INT, DECIMAL, DATETIME, BOOLEAN, and NULL columns
5. Add special-case handling for BYTEA/BLOB if needed
