<?php
declare(strict_types=1);

namespace Reut\Query;

use Reut\DB\DataBase;
use Reut\DB\Exceptions\DatabaseQueryException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * ReutQueries - Advanced query builder for REUT framework
 * Provides select and where query parameter support
 * 
 * Usage: Enable via REUT_QUERIES_ENABLED=true in .env
 */
class ReutQueries
{
    private DataBase $db;
    
    public function __construct(DataBase $db)
    {
        $this->db = $db;
    }
    
    /**
     * Check if ReutQueries feature is enabled
     */
    public static function isEnabled(): bool
    {
        return filter_var($_ENV['REUT_QUERIES_ENABLED'] ?? 'false', FILTER_VALIDATE_BOOLEAN);
    }
    
    /**
     * Handle findAll with optional query parameters
     * Automatically falls back to default behavior if disabled
     */
    public static function handleFindAll(DataBase $instance, Request $request): DataBase
    {
        if (!self::isEnabled()) {
            return $instance->findAll();
        }
        
        $params = $request->getQueryParams();
        $rawQuery = $request->getUri()->getQuery();
        $queryData = self::parseQueryParams($params, $rawQuery);
        $selectColumns = $queryData['select'];
        $whereConditions = $queryData['where'];
        $withRelationships = $queryData['with'] ?? [];
        $joins = $queryData['joins'] ?? [];
        
        $result = null;
        
        // Auto-create JOINs for relationship filters if not already provided
        if (self::hasRelationshipFilters($whereConditions) && empty($joins)) {
            $joins = self::autoCreateJoinsForFilters($instance, $whereConditions);
        }
        
        // If we have joins or relationship filters, use query builder
        if (!empty($joins) || self::hasRelationshipFilters($whereConditions)) {
            $reutQueries = new self($instance);
            // Fix join ON clauses to use main table name
            $joins = self::fixJoinOnClauses($joins, $instance->tableName);
            $result = $reutQueries->query($selectColumns, $whereConditions, $joins);
        } elseif (empty($selectColumns) && empty($whereConditions)) {
            $result = $instance->findAll();
        } else {
            $reutQueries = new self($instance);
            $result = $reutQueries->query($selectColumns, $whereConditions, []);
        }
        
        // Load relationships if requested (eager loading)
        if (!empty($withRelationships)) {
            $result->with($withRelationships);
        }
        
        return $result;
    }
    
    /**
     * Handle findOne with optional select parameters
     * Automatically falls back to default behavior if disabled
     */
    public static function handleFindOne(DataBase $instance, string $id, Request $request): DataBase
    {
        $data = $instance->findOne(['id' => $id]);
        
        // Check if findOne returned an error (string) or failed
        if (!($data instanceof DataBase) || !$data->results) {
            return $data instanceof DataBase ? $data : $instance;
        }
        
        if (!self::isEnabled()) {
            return $data;
        }
        
        $params = $request->getQueryParams();
        $rawQuery = $request->getUri()->getQuery();
        $queryData = self::parseQueryParams($params, $rawQuery);
        $selectColumns = $queryData['select'];
        $withRelationships = $queryData['with'] ?? [];
        
        // Load relationships if requested (before select to preserve all data)
        if (!empty($withRelationships)) {
            $data->with($withRelationships);
        }
        
        if (!empty($selectColumns)) {
            $reutQueries = new self($instance);
            // Convert single result to array for applySelect, then convert back
            $singleResult = $data->results;
            $data->results = [$singleResult];
            $data->results = $reutQueries->applySelect($data->results, $selectColumns);
            // Convert back to single result
            $data->results = $data->results[0] ?? null;
        }
        
        return $data;
    }
    
    /**
     * Handle update with optional condition verification
     * Returns Response object with appropriate status code
     */
    public static function handleUpdate(DataBase $instance, string $id, array $input, Request $request, Response $response): Response
    {
        // Check if feature is enabled and has conditions
        if (self::isEnabled()) {
            $params = $request->getQueryParams();
            $rawQuery = $request->getUri()->getQuery();
            $queryData = self::parseQueryParams($params, $rawQuery);
            $whereConditions = $queryData['where'];
            
            if (!empty($whereConditions)) {
                // Verify conditions match before updating
                $reutQueries = new self($instance);
                $verifyConditions = array_merge(['id' => ['eq' => $id]], $whereConditions);
                $verifyData = $reutQueries->query([], $verifyConditions)->results;
                
                if (empty($verifyData)) {
                    $response->getBody()->write(json_encode([
                        'status' => false,
                        'error' => 'Record not found or does not match conditions'
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
                }
            }
        }
        
        // Perform update
        $result = $instance->update($input, ['id' => $id]);
        
        $response->getBody()->write(json_encode(['status' => $result]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    /**
     * Handle delete with optional condition verification
     * Returns Response object with appropriate status code
     */
    public static function handleDelete(DataBase $instance, string $id, Request $request, Response $response): Response
    {
        // Check if feature is enabled and has conditions
        if (self::isEnabled()) {
            $params = $request->getQueryParams();
            $rawQuery = $request->getUri()->getQuery();
            $queryData = self::parseQueryParams($params, $rawQuery);
            $whereConditions = $queryData['where'];
            
            if (!empty($whereConditions)) {
                // Verify conditions match before deleting
                $reutQueries = new self($instance);
                $verifyConditions = array_merge(['id' => ['eq' => $id]], $whereConditions);
                $verifyData = $reutQueries->query([], $verifyConditions)->results;
                
                if (empty($verifyData)) {
                    $response->getBody()->write(json_encode([
                        'status' => false,
                        'error' => 'Record not found or does not match conditions'
                    ]));
                    return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
                }
            }
        }
        
        // Perform delete
        $result = $instance->delete(['id' => $id]);
        
        $response->getBody()->write(json_encode(['status' => $result]));
        return $response->withHeader('Content-Type', 'application/json');
    }
    
    /**
     * Parse query parameters from request
     * 
     * @param array $queryParams Query parameters from request (may have dots converted to underscores)
     * @param string|null $rawQueryString Optional raw query string to parse manually
     * @return array ['select' => [...], 'where' => [...], 'with' => [...], 'joins' => [...]]
     */
    public static function parseQueryParams(array $queryParams, ?string $rawQueryString = null): array
    {
        $select = [];
        $where = [];
        $with = [];
        $joins = [];
        
        // If raw query string is provided, parse it manually to preserve dots
        if ($rawQueryString !== null) {
            $pairs = explode('&', $rawQueryString);
            foreach ($pairs as $pair) {
                if (empty($pair)) continue;
                $parts = explode('=', $pair, 2);
                $key = urldecode($parts[0]);
                $value = isset($parts[1]) ? urldecode($parts[1]) : '';
                
                if ($key === 'select') {
                    $select = array_map('trim', explode(',', $value));
                    $select = array_filter($select);
                } elseif ($key === 'with') {
                    $with = array_map('trim', explode(',', $value));
                    $with = array_filter($with);
                } elseif ($key === 'join') {
                    // Parse join parameter: ?join=table:local_col=ref_col:type:alias
                    $joins = self::parseJoinParameter($value);
                } elseif (!in_array($key, ['page', 'limit', 'offset', 'order', 'orderBy'])) {
                    // Check if it's a relationship-based filter: table.column=value
                    // Note: PHP converts dots to underscores, so we need to check the raw key
                    // But if we're parsing from raw query string, dots are preserved
                    if (strpos($key, '.') !== false) {
                        $keyParts = explode('.', $key);
                        
                        // Check if it's an operator-style filter: column.operator.value
                        if (count($keyParts) === 3) {
                            [$column, $operator, $filterValue] = $keyParts;
                            $column = trim($column);
                            $operator = strtolower(trim($operator));
                            
                            if (!isset($where[$column])) {
                                $where[$column] = [];
                            }
                            $where[$column][$operator] = $filterValue;
                        } elseif (count($keyParts) === 2) {
                            // Could be table.column=value (relationship filter) or column.operator=value
                            [$part1, $part2] = $keyParts;
                            
                            // If part2 contains an operator, treat as column.operator
                            $operators = ['eq', 'neq', 'gt', 'gte', 'lt', 'lte', 'like'];
                            if (in_array($part2, $operators)) {
                                // This is column.operator format, but we need the value
                                // This case is handled above with 3 parts
                                $where[$part1] = ['eq' => $value];
                            } else {
                                // Likely table.column format (relationship filter)
                                $where[$key] = ['eq' => $value];
                            }
                        } else {
                            // More than 3 parts - could be nested relationship filter
                            $where[$key] = ['eq' => $value];
                        }
                    } else {
                        // Check if key might be converted underscore (user_name instead of user.name)
                        // Try to detect relationship filters by checking if underscore-separated parts
                        // match a known table name pattern
                        if (strpos($key, '_') !== false) {
                            // Could be converted dot - but we can't reliably detect this
                            // So we'll treat it as a regular column filter
                            $where[$key] = ['eq' => $value];
                        } else {
                            // Simple equality: ?name=john
                            $where[$key] = ['eq' => $value];
                        }
                    }
                }
            }
        } else {
            // Fallback: Parse from array (dots may have been converted to underscores)
            // Parse select parameter: ?select=name,age,email
            if (isset($queryParams['select']) && !empty($queryParams['select'])) {
                $select = array_map('trim', explode(',', $queryParams['select']));
                $select = array_filter($select);
            }
            
            // Parse with parameter: ?with=user,comments
            if (isset($queryParams['with']) && !empty($queryParams['with'])) {
                $with = array_map('trim', explode(',', $queryParams['with']));
                $with = array_filter($with);
            }
            
            // Parse join parameter: ?join=table:local_col=ref_col:type:alias
            if (isset($queryParams['join']) && !empty($queryParams['join'])) {
                $joins = self::parseJoinParameter($queryParams['join']);
            }
            
            // Parse where parameters
            // Note: PHP converts dots to underscores, so we need to check for underscores too
            foreach ($queryParams as $key => $value) {
                // Skip reserved parameters
                if (in_array($key, ['select', 'with', 'page', 'limit', 'offset', 'order', 'orderBy'])) {
                    continue;
                }
                
                // Check if it might be an operator-style filter (with underscores instead of dots)
                // Pattern: column_operator_value (e.g., status_eq_published)
                if (strpos($key, '_') !== false && !isset($queryParams[str_replace('_', '.', $key)])) {
                    $parts = explode('_', $key);
                    if (count($parts) >= 3) {
                        // Try to find a valid operator in the parts
                        $operators = ['eq', 'neq', 'ne', 'gt', 'gte', 'lt', 'lte', 'like', 'ilike', 'in', 'is', 'between'];
                        foreach ($operators as $op) {
                            $opPos = array_search($op, $parts);
                            if ($opPos !== false && $opPos > 0 && $opPos < count($parts) - 1) {
                                $column = implode('_', array_slice($parts, 0, $opPos));
                                $operator = $op;
                                $filterValue = implode('_', array_slice($parts, $opPos + 1));
                                
                                if (!isset($where[$column])) {
                                    $where[$column] = [];
                                }
                                $where[$column][$operator] = $filterValue;
                                continue 2;
                            }
                        }
                    }
                }
                
                // Check if it's an operator-style filter with dots (if PHP didn't convert them)
                if (strpos($key, '.') !== false) {
                    $parts = explode('.', $key, 3);
                    if (count($parts) === 3) {
                        [$column, $operator, $filterValue] = $parts;
                        $column = trim($column);
                        $operator = strtolower(trim($operator));
                        
                        if (!isset($where[$column])) {
                            $where[$column] = [];
                        }
                        $where[$column][$operator] = $filterValue;
                        continue;
                    }
                }
                
                // Simple equality: ?name=john
                if (!empty($value) || $value === '0') {
                    $where[$key] = ['eq' => $value];
                }
            }
        }
        
        return ['select' => $select, 'where' => $where, 'with' => $with, 'joins' => $joins];
    }

    /**
     * Parse join parameter string
     * Format: table:local_col=ref_col:type:alias or multiple joins separated by |
     * Example: users:user_id=id:INNER:user|comments:post_id=id:LEFT:comments
     * 
     * @param string $joinParam Join parameter string
     * @return array Array of join definitions
     */
    private static function parseJoinParameter(string $joinParam): array
    {
        $joins = [];
        
        // Split multiple joins by |
        $joinStrings = explode('|', $joinParam);
        
        foreach ($joinStrings as $joinStr) {
            $joinStr = trim($joinStr);
            if (empty($joinStr)) continue;
            
            // Parse: table:local_col=ref_col:type:alias
            $parts = explode(':', $joinStr);
            
            if (count($parts) < 2) {
                continue; // Invalid format
            }
            
            $table = trim($parts[0]);
            $onClause = trim($parts[1]);
            
            // Parse on clause: local_col=ref_col
            if (strpos($onClause, '=') === false) {
                continue; // Invalid format
            }
            
            [$localCol, $refCol] = explode('=', $onClause, 2);
            $localCol = trim($localCol);
            $refCol = trim($refCol);
            
            $type = isset($parts[2]) ? strtoupper(trim($parts[2])) : 'INNER';
            $alias = isset($parts[3]) ? trim($parts[3]) : $table;
            
            // Build ON clause - will be fixed with main table name later
            $joins[] = [
                'table' => $table,
                'alias' => $alias,
                'type' => $type,
                'local_col' => $localCol,
                'ref_col' => $refCol
            ];
        }
        
        return $joins;
    }

    /**
     * Fix join ON clauses to use main table name
     * 
     * @param array $joins Join definitions
     * @param string $mainTable Main table name
     * @return array Fixed join definitions
     */
    private static function fixJoinOnClauses(array $joins, string $mainTable): array
    {
        foreach ($joins as &$join) {
            // Only fix if ON clause not already set
            if (!isset($join['on'])) {
                $localColumn = $join['local_column'] ?? $join['local_col'] ?? null;
                $referencedColumn = $join['referenced_column'] ?? $join['ref_col'] ?? 'id';
                $alias = $join['alias'] ?? strtolower($join['table']);
                
                if ($localColumn) {
                    $join['on'] = "`{$mainTable}`.`{$localColumn}` = `{$alias}`.`{$referencedColumn}`";
                }
            }
        }
        return $joins;
    }

    /**
     * Check if where conditions contain relationship-based filters
     * 
     * @param array $whereConditions Where conditions
     * @return bool True if relationship filters are present
     */
    private static function hasRelationshipFilters(array $whereConditions): bool
    {
        foreach ($whereConditions as $key => $value) {
            if (strpos($key, '.') !== false) {
                return true;
            }
        }
        return false;
    }
    
    /**
     * Automatically create JOINs for relationship-based filters
     * 
     * @param DataBase $instance The database instance
     * @param array $whereConditions Where conditions with relationship filters
     * @return array Array of join definitions
     */
    private static function autoCreateJoinsForFilters(DataBase $instance, array $whereConditions): array
    {
        $joins = [];
        $joinedTables = []; // Track tables we've already joined (by actual table name)
        $tableAliasMap = []; // Map filter table name to actual table name and alias
        
        foreach ($whereConditions as $column => $condition) {
            if (strpos($column, '.') !== false) {
                [$filterTableName, $columnName] = explode('.', $column, 2);
                
                // Find the actual table name (case-insensitive match)
                $actualTableName = self::findTableByName($instance, $filterTableName);
                
                if (!$actualTableName) {
                    continue; // Table not found, skip
                }
                
                // Skip if we've already joined this table
                if (in_array($actualTableName, $joinedTables)) {
                    continue;
                }
                
                // Find the foreign key that references this table
                $fkColumn = self::findForeignKeyToTable($instance, $actualTableName);
                
                if ($fkColumn) {
                    $fkMetadata = null;
                    $foreignKeys = $instance->getForeignKeys();
                    foreach ($foreignKeys as $fk) {
                        if (strcasecmp($fk['referenced_table'], $actualTableName) === 0 && $fk['column'] === $fkColumn) {
                            $fkMetadata = $fk;
                            break;
                        }
                    }
                    
                    $referencedColumn = $fkMetadata ? $fkMetadata['referenced_column'] : 'id';
                    $alias = strtolower($filterTableName); // Use the filter table name as alias
                    
                    // Create a JOIN definition (will be fixed by fixJoinOnClauses)
                    $joins[] = [
                        'table' => $actualTableName,
                        'local_column' => $fkColumn,
                        'referenced_column' => $referencedColumn,
                        'type' => 'INNER',
                        'alias' => $alias
                    ];
                    $joinedTables[] = $actualTableName;
                    $tableAliasMap[$filterTableName] = [
                        'actual_table' => $actualTableName,
                        'alias' => $alias
                    ];
                }
            }
        }
        
        return $joins;
    }
    
    /**
     * Find the actual table name by case-insensitive match
     * 
     * @param DataBase $instance The database instance
     * @param string $filterTableName The table name from filter (may be lowercase)
     * @return string|null The actual table name or null if not found
     */
    private static function findTableByName(DataBase $instance, string $filterTableName): ?string
    {
        // First, check if any FK references a table with this name (case-insensitive)
        // Also handle singular/plural variations (e.g., "user" -> "Users")
        $foreignKeys = $instance->getForeignKeys();
        
        $filterLower = strtolower($filterTableName);
        
        foreach ($foreignKeys as $fk) {
            $refTableLower = strtolower($fk['referenced_table']);
            
            // Exact match (case-insensitive)
            if ($refTableLower === $filterLower) {
                return $fk['referenced_table']; // Return actual case
            }
            
            // Handle singular/plural: "user" matches "Users", "users"
            // Check if one is singular and other is plural
            $filterSingular = rtrim($filterLower, 's');
            $refSingular = rtrim($refTableLower, 's');
            
            // Match if singular forms are the same (e.g., "user" matches "Users")
            if ($filterSingular === $refSingular) {
                return $fk['referenced_table']; // Return actual case
            }
            
            // Also check if filter is singular and ref is plural (or vice versa)
            if ($filterLower === $refSingular || $refTableLower === $filterSingular) {
                return $fk['referenced_table']; // Return actual case
            }
        }
        
        // If not found in FKs, try querying INFORMATION_SCHEMA
        try {
            $instance->connect();
            if ($instance->pdo) {
                // Try exact match first
                $stmt = $instance->pdo->prepare("
                    SELECT TABLE_NAME 
                    FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND LOWER(TABLE_NAME) = LOWER(:tableName)
                    LIMIT 1
                ");
                $stmt->execute(['tableName' => $filterTableName]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($result) {
                    return $result['TABLE_NAME'];
                }
                
                // Try singular/plural variations
                $filterSingular = rtrim($filterLower, 's');
                $stmt = $instance->pdo->prepare("
                    SELECT TABLE_NAME 
                    FROM INFORMATION_SCHEMA.TABLES 
                    WHERE TABLE_SCHEMA = DATABASE() 
                    AND (LOWER(TABLE_NAME) = :singular OR LOWER(TABLE_NAME) = :plural)
                    LIMIT 1
                ");
                $stmt->execute([
                    'singular' => $filterSingular,
                    'plural' => $filterSingular . 's'
                ]);
                $result = $stmt->fetch(\PDO::FETCH_ASSOC);
                if ($result) {
                    return $result['TABLE_NAME'];
                }
            }
        } catch (\PDOException $e) {
            // Silently fail
        }
        
        return null;
    }
    
    /**
     * Find the foreign key column that references a given table
     * 
     * @param DataBase $instance The database instance
     * @param string $referencedTable The table being referenced
     * @return string|null The foreign key column name or null if not found
     */
    private static function findForeignKeyToTable(DataBase $instance, string $referencedTable): ?string
    {
        $foreignKeys = $instance->getForeignKeys();
        
        foreach ($foreignKeys as $fk) {
            if (strcasecmp($fk['referenced_table'], $referencedTable) === 0) {
                return $fk['column'];
            }
        }
        
        return null;
    }
    
    /**
     * Execute query with select and where clauses
     * 
     * @param array $selectColumns Array of column names to select
     * @param array $whereConditions Array of where conditions
     * @param array $joins Array of join definitions
     * @return DataBase Returns the database instance with results
     */
    public function query(array $selectColumns = [], array $whereConditions = [], array $joins = []): DataBase
    {
        $this->db->connect();
        
        if (!$this->db->pdo) {
            throw new DatabaseQueryException("Database connection failed");
        }
        
        try {
            // Build JOIN clauses
            $joinClause = $this->buildJoinClause($joins);
            
            // Build SELECT clause with table aliases
            $selectClause = $this->buildSelectClause($selectColumns, $joins);
            
            // Build WHERE clause (including relationship-based filters)
            $whereData = $this->buildWhereClause($whereConditions, $joins);
            $whereClause = $whereData['clause'];
            $params = $whereData['params'];
            
            // Build and execute query
            $tableName = $this->db->tableName;
            $query = "SELECT {$selectClause} FROM `{$tableName}` {$joinClause} {$whereClause}";
            
            // Temporary debug - remove after fixing
            if (empty($results = [])) { // This will always be false, just to add breakpoint
                error_log("DEBUG SQL: " . $query);
                error_log("DEBUG Joins: " . json_encode($joins));
                error_log("DEBUG Where: " . json_encode($whereConditions));
            }
            
            $stmt = $this->db->pdo->prepare($query);
            $stmt->execute($params);
            $results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
            // Format results based on join structure
            $this->db->results = $this->formatJoinResults($results, $selectColumns, $joins);
            
            return $this->db;
        } catch (\PDOException $e) {
            throw new DatabaseQueryException(
                "Query failed: " . $e->getMessage(),
                (int)$e->getCode(),
                $e,
                $query ?? '',
                $params ?? [],
                $e->errorInfo ?? []
            );
        }
    }

    /**
     * Build JOIN clause from join definitions
     * 
     * @param array $joins Array of join definitions
     * @return string JOIN clause
     */
    private function buildJoinClause(array $joins): string
    {
        if (empty($joins)) {
            return '';
        }

        $joinParts = [];
        foreach ($joins as $join) {
            $type = strtoupper($join['type'] ?? 'INNER');
            $table = $join['table'];
            $alias = $join['alias'] ?? strtolower($table);
            
            // Build ON clause if not provided
            if (isset($join['on'])) {
                $on = $join['on'];
            } else {
                // Auto-build ON clause from local_column and referenced_column
                $localColumn = $join['local_column'] ?? $join['local_col'] ?? null;
                $referencedColumn = $join['referenced_column'] ?? $join['ref_col'] ?? 'id';
                
                if ($localColumn) {
                    $on = "`{$this->db->tableName}`.`{$localColumn}` = `{$alias}`.`{$referencedColumn}`";
                } else {
                    continue; // Skip invalid join
                }
            }
            
            $this->validateIdentifier($table);
            $this->validateIdentifier($alias);
            // Use backticks for table name and alias
            $joinParts[] = "{$type} JOIN `{$table}` AS `{$alias}` ON {$on}";
        }

        return implode(' ', $joinParts);
    }

    /**
     * Build SELECT clause with table aliases
     * 
     * @param array $selectColumns Column names to select
     * @param array $joins Join definitions
     * @return string SELECT clause
     */
    private function buildSelectClause(array $selectColumns, array $joins): string
    {
        if (empty($selectColumns)) {
            // If no specific columns, select all with table prefixes
            $clauses = ["`{$this->db->tableName}`.*"];
            
            foreach ($joins as $join) {
                $table = $join['table'];
                $alias = $join['alias'] ?? strtolower($table);
                // Use the alias, not the table name, for joined tables
                $clauses[] = "`{$alias}`.*";
            }
            
            return implode(', ', $clauses);
        }

        $validatedColumns = [];
        $joinedTables = [];
        foreach ($joins as $join) {
            $tableName = $join['table'];
            $alias = $join['alias'] ?? strtolower($tableName);
            // Map both table name and alias
            $joinedTables[$tableName] = $alias;
            $joinedTables[strtolower($tableName)] = $alias;
            $joinedTables[$alias] = $alias;
        }
        
        // Also map main table name (case-insensitive)
        $mainTableLower = strtolower($this->db->tableName);
        $joinedTables[$this->db->tableName] = $this->db->tableName;
        $joinedTables[$mainTableLower] = $this->db->tableName;

        foreach ($selectColumns as $col) {
            $col = trim($col);
            
            // Handle table.column syntax
            if (strpos($col, '.') !== false) {
                [$table, $column] = explode('.', $col, 2);
                $this->validateIdentifier($table);
                $this->validateIdentifier($column);
                
                // Check if table is a joined table and use its alias, or use main table name
                $tableRef = $joinedTables[$table] ?? $joinedTables[strtolower($table)] ?? $table;
                
                // Handle wildcard (table.*)
                if ($column === '*') {
                    $validatedColumns[] = "`{$tableRef}`.*";
                } else {
                    // Use alias if available, otherwise use table name
                    $validatedColumns[] = "`{$tableRef}`.`{$column}` AS `{$tableRef}_{$column}`";
                }
            } else {
                // Column from main table
                $this->validateIdentifier($col);
                $validatedColumns[] = "`{$this->db->tableName}`.`{$col}`";
            }
        }

        return implode(', ', $validatedColumns);
    }

    /**
     * Build WHERE clause including relationship-based filters
     * 
     * @param array $whereConditions Where conditions
     * @param array $joins Join definitions
     * @return array ['clause' => string, 'params' => array]
     */
    private function buildWhereClause(array $whereConditions, array $joins): array
    {
        $whereParts = [];
        $params = [];

        if (empty($whereConditions)) {
            return ['clause' => '', 'params' => []];
        }

        // Build a map of filter table names to aliases from joins
        // The filter uses "user" but the table is "Users", and the alias is "user"
        $tableAliasMap = [];
        foreach ($joins as $join) {
            $tableName = $join['table'];
            $alias = $join['alias'] ?? strtolower($tableName);
            // Map actual table name (case-sensitive and lowercase) to alias
            $tableAliasMap[strtolower($tableName)] = $alias;
            $tableAliasMap[$tableName] = $alias;
            // Also map the alias itself (in case filter uses exact alias)
            $tableAliasMap[$alias] = $alias;
        }

        foreach ($whereConditions as $column => $condition) {
            // Handle relationship-based filters (table.column)
            if (strpos($column, '.') !== false) {
                [$filterTable, $col] = explode('.', $column, 2);
                $this->validateIdentifier($filterTable);
                $this->validateIdentifier($col);
                
                // Use alias if available - check both exact match and lowercase match
                // The filter table name (e.g., "user") should map to the alias from JOIN
                $tableRef = $tableAliasMap[$filterTable] ?? $tableAliasMap[strtolower($filterTable)] ?? $filterTable;
                
                if (is_array($condition)) {
                    foreach ($condition as $operator => $value) {
                        $operator = strtolower($operator);
                        $whereParts[] = $this->buildWhereCondition("`{$tableRef}`.`{$col}`", $operator, $value, $params, false);
                    }
                } else {
                    $whereParts[] = "`{$tableRef}`.`{$col}` = ?";
                    $params[] = $condition;
                }
            } else {
                // Regular column from main table
                $this->validateIdentifier($column);
                
                if (is_array($condition)) {
                    foreach ($condition as $operator => $value) {
                        $operator = strtolower($operator);
                        $whereParts[] = $this->buildWhereCondition("`{$this->db->tableName}`.`{$column}`", $operator, $value, $params, false);
                    }
                } else {
                    $whereParts[] = "`{$this->db->tableName}`.`{$column}` = ?";
                    $params[] = $condition;
                }
            }
        }

        $whereClause = !empty($whereParts) ? "WHERE " . implode(" AND ", $whereParts) : '';
        return ['clause' => $whereClause, 'params' => $params];
    }

    /**
     * Format join results into nested structure
     * 
     * @param array $results Raw query results
     * @param array $selectColumns Selected columns
     * @param array $joins Join definitions
     * @return array Formatted results
     */
    private function formatJoinResults(array $results, array $selectColumns, array $joins): array
    {
        if (empty($joins)) {
            return $results;
        }

        $formatted = [];
        foreach ($results as $row) {
            $mainRecord = [];
            $relatedRecords = [];

            // Separate main table columns from joined table columns
            foreach ($row as $key => $value) {
                $foundInJoin = false;
                
                foreach ($joins as $join) {
                    $table = $join['table'];
                    $alias = $join['alias'] ?? $table;
                    
                    // Check if this column belongs to a joined table
                    if (strpos($key, $table . '_') === 0 || strpos($key, $alias . '_') === 0) {
                        $relatedKey = str_replace([$table . '_', $alias . '_'], '', $key);
                        if (!isset($relatedRecords[$alias])) {
                            $relatedRecords[$alias] = [];
                        }
                        $relatedRecords[$alias][$relatedKey] = $value;
                        $foundInJoin = true;
                        break;
                    }
                }
                
                if (!$foundInJoin) {
                    $mainRecord[$key] = $value;
                }
            }

            // Merge related records into main record
            foreach ($relatedRecords as $alias => $relatedData) {
                $mainRecord[$alias] = $relatedData;
            }

            $formatted[] = $mainRecord;
        }

        return $formatted;
    }
    
    /**
     * Build a WHERE condition based on operator
     */
    private function buildWhereCondition(string $column, string $operator, $value, array &$params, bool $addBackticks = true): string
    {
        $col = $addBackticks ? "`{$column}`" : $column;
        
        switch ($operator) {
            case 'eq':
                $params[] = $value;
                return "{$col} = ?";
                
            case 'neq':
            case 'ne':
                $params[] = $value;
                return "{$col} != ?";
                
            case 'gt':
                $params[] = $value;
                return "{$col} > ?";
                
            case 'gte':
                $params[] = $value;
                return "{$col} >= ?";
                
            case 'lt':
                $params[] = $value;
                return "{$col} < ?";
                
            case 'lte':
                $params[] = $value;
                return "{$col} <= ?";
                
            case 'like':
                $params[] = "%{$value}%";
                return "{$col} LIKE ?";
                
            case 'ilike':
                $params[] = "%" . strtolower($value) . "%";
                return "LOWER({$col}) LIKE ?";
                
            case 'in':
                if (is_array($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $params = array_merge($params, $value);
                    return "{$col} IN ({$placeholders})";
                }
                // Handle comma-separated string
                $values = explode(',', $value);
                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $params = array_merge($params, $values);
                return "{$col} IN ({$placeholders})";
                
            case 'is':
                if ($value === null || strtolower($value) === 'null') {
                    return "{$col} IS NULL";
                }
                return "{$col} IS NOT NULL";
                
            case 'between':
                if (is_array($value)) {
                    if (count($value) === 2) {
                        $params[] = $value[0];
                        $params[] = $value[1];
                        return "{$col} BETWEEN ? AND ?";
                    }
                }
                // Handle comma-separated string
                $values = explode(',', $value);
                if (count($values) === 2) {
                    $params[] = trim($values[0]);
                    $params[] = trim($values[1]);
                    return "{$col} BETWEEN ? AND ?";
                }
                // Fallback to equality
                $params[] = $value;
                return "{$col} = ?";
                
            default:
                // Default to equality
                $params[] = $value;
                return "{$col} = ?";
        }
    }
    
    /**
     * Validate SQL identifier to prevent SQL injection
     */
    private function validateIdentifier(string $identifier): void
    {
        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $identifier)) {
            throw new \InvalidArgumentException("Invalid SQL identifier: {$identifier}");
        }
    }
    
    /**
     * Apply select to existing results (post-query filtering)
     * Handles both single results (associative array) and arrays of results
     */
    public function applySelect($results, array $selectColumns)
    {
        if (empty($selectColumns) || empty($results)) {
            return $results;
        }
        
        // Check if it's a single result (associative array) vs array of results
        // Single result: ['id' => 1, 'name' => 'John'] (associative array with string keys)
        // Array of results: [['id' => 1], ['id' => 2]] (array with numeric sequential keys starting from 0)
        $isSingle = false;
        if (is_array($results)) {
            $keys = array_keys($results);
            // Check if keys are numeric and sequential starting from 0
            // If not, it's a single associative array result
            if (empty($keys)) {
                $isSingle = false; // Empty array, treat as array of results
            } elseif (!is_numeric($keys[0]) || $keys[0] !== 0) {
                $isSingle = true; // First key is not 0 or not numeric = associative array
            } else {
                // Check if keys are sequential: [0, 1, 2, ...]
                $isSingle = $keys !== range(0, count($keys) - 1);
            }
        }
        
        // Normalize to array of rows for processing
        if ($isSingle) {
            $rows = [$results];
        } else {
            $rows = $results;
        }
        
        $filtered = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $filteredRow = [];
            foreach ($selectColumns as $col) {
                $col = trim($col);
                if (isset($row[$col])) {
                    $filteredRow[$col] = $row[$col];
                }
            }
            $filtered[] = $filteredRow;
        }
        
        // Return single result if input was single, otherwise return array
        return $isSingle ? ($filtered[0] ?? []) : $filtered;
    }
}

