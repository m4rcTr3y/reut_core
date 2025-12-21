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
        
        if (empty($selectColumns) && empty($whereConditions)) {
            return $instance->findAll();
        }
        
        $reutQueries = new self($instance);
        return $reutQueries->query($selectColumns, $whereConditions);
    }
    
    /**
     * Handle findOne with optional select parameters
     * Automatically falls back to default behavior if disabled
     */
    public static function handleFindOne(DataBase $instance, string $id, Request $request): DataBase
    {
        $data = $instance->findOne(['id' => $id]);
        
        if (!self::isEnabled() || !$data->results) {
            return $data;
        }
        
        $params = $request->getQueryParams();
        $rawQuery = $request->getUri()->getQuery();
        $queryData = self::parseQueryParams($params, $rawQuery);
        $selectColumns = $queryData['select'];
        
        if (!empty($selectColumns)) {
            $reutQueries = new self($instance);
            // Pass the single result directly, applySelect will handle it correctly
            $data->results = $reutQueries->applySelect($data->results, $selectColumns);
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
     * @return array ['select' => [...], 'where' => [...]]
     */
    public static function parseQueryParams(array $queryParams, ?string $rawQueryString = null): array
    {
        $select = [];
        $where = [];
        
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
                } elseif (!in_array($key, ['page', 'limit', 'offset', 'order', 'orderBy'])) {
                    // Check if it's an operator-style filter: column.operator.value
                    if (strpos($key, '.') !== false) {
                        $keyParts = explode('.', $key, 3);
                        if (count($keyParts) === 3) {
                            [$column, $operator, $filterValue] = $keyParts;
                            $column = trim($column);
                            $operator = strtolower(trim($operator));
                            
                            if (!isset($where[$column])) {
                                $where[$column] = [];
                            }
                            $where[$column][$operator] = $filterValue;
                        }
                    } else {
                        // Simple equality: ?name=john
                        $where[$key] = ['eq' => $value];
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
            
            // Parse where parameters
            // Note: PHP converts dots to underscores, so we need to check for underscores too
            foreach ($queryParams as $key => $value) {
                // Skip reserved parameters
                if (in_array($key, ['select', 'page', 'limit', 'offset', 'order', 'orderBy'])) {
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
        
        return ['select' => $select, 'where' => $where];
    }
    
    /**
     * Execute query with select and where clauses
     * 
     * @param array $selectColumns Array of column names to select
     * @param array $whereConditions Array of where conditions
     * @return DataBase Returns the database instance with results
     */
    public function query(array $selectColumns = [], array $whereConditions = []): DataBase
    {
        $this->db->connect();
        
        if (!$this->db->pdo) {
            throw new DatabaseQueryException("Database connection failed");
        }
        
        try {
            // Build SELECT clause
            if (empty($selectColumns)) {
                $selectClause = "*";
            } else {
                $validatedColumns = [];
                foreach ($selectColumns as $col) {
                    $col = trim($col);
                    $this->validateIdentifier($col);
                    $validatedColumns[] = "`{$col}`";
                }
                $selectClause = implode(", ", $validatedColumns);
            }
            
            // Build WHERE clause
            $whereClause = "";
            $params = [];
            
            if (!empty($whereConditions)) {
                $whereParts = [];
                
                foreach ($whereConditions as $column => $condition) {
                    $this->validateIdentifier($column);
                    
                    if (is_array($condition)) {
                        foreach ($condition as $operator => $value) {
                            $operator = strtolower($operator);
                            $whereParts[] = $this->buildWhereCondition($column, $operator, $value, $params);
                        }
                    } else {
                        // Simple equality
                        $whereParts[] = "`{$column}` = ?";
                        $params[] = $condition;
                    }
                }
                
                if (!empty($whereParts)) {
                    $whereClause = "WHERE " . implode(" AND ", $whereParts);
                }
            }
            
            // Build and execute query
            $tableName = $this->db->tableName;
            $query = "SELECT {$selectClause} FROM `{$tableName}` {$whereClause}";
            
            $stmt = $this->db->pdo->prepare($query);
            $stmt->execute($params);
            $this->db->results = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            
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
     * Build a WHERE condition based on operator
     */
    private function buildWhereCondition(string $column, string $operator, $value, array &$params): string
    {
        switch ($operator) {
            case 'eq':
                $params[] = $value;
                return "`{$column}` = ?";
                
            case 'neq':
            case 'ne':
                $params[] = $value;
                return "`{$column}` != ?";
                
            case 'gt':
                $params[] = $value;
                return "`{$column}` > ?";
                
            case 'gte':
                $params[] = $value;
                return "`{$column}` >= ?";
                
            case 'lt':
                $params[] = $value;
                return "`{$column}` < ?";
                
            case 'lte':
                $params[] = $value;
                return "`{$column}` <= ?";
                
            case 'like':
                $params[] = "%{$value}%";
                return "`{$column}` LIKE ?";
                
            case 'ilike':
                $params[] = "%" . strtolower($value) . "%";
                return "LOWER(`{$column}`) LIKE ?";
                
            case 'in':
                if (is_array($value)) {
                    $placeholders = implode(',', array_fill(0, count($value), '?'));
                    $params = array_merge($params, $value);
                    return "`{$column}` IN ({$placeholders})";
                }
                // Handle comma-separated string
                $values = explode(',', $value);
                $placeholders = implode(',', array_fill(0, count($values), '?'));
                $params = array_merge($params, $values);
                return "`{$column}` IN ({$placeholders})";
                
            case 'is':
                if ($value === null || strtolower($value) === 'null') {
                    return "`{$column}` IS NULL";
                }
                return "`{$column}` IS NOT NULL";
                
            case 'between':
                if (is_array($value)) {
                    if (count($value) === 2) {
                        $params[] = $value[0];
                        $params[] = $value[1];
                        return "`{$column}` BETWEEN ? AND ?";
                    }
                }
                // Handle comma-separated string
                $values = explode(',', $value);
                if (count($values) === 2) {
                    $params[] = trim($values[0]);
                    $params[] = trim($values[1]);
                    return "`{$column}` BETWEEN ? AND ?";
                }
                // Fallback to equality
                $params[] = $value;
                return "`{$column}` = ?";
                
            default:
                // Default to equality
                $params[] = $value;
                return "`{$column}` = ?";
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

