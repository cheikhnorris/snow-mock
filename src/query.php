<?php
// ServiceNow encoded query -> SQL. Supports ^, ^OR, ^NQ, ORDERBY / ORDERBYDESC and the operators
// =, !=, IN, NOT IN, LIKE, NOT LIKE, STARTSWITH, ENDSWITH, >, >=, <, <=, ISEMPTY, ISNOTEMPTY.
// Conditions on unknown fields are ignored (default ServiceNow behaviour).

const QUERY_OPERATORS = ['ISNOTEMPTY', 'ISEMPTY', 'NOT IN', 'NOT LIKE', 'STARTSWITH', 'ENDSWITH', 'LIKE', 'IN', '!=', '>=', '<=', '=', '>', '<'];

/** @return array{0: string, 1: array, 2: string} where, params, order by */
function build_query(string $table, string $encoded): array
{
    $cols = array_flip(columns($table));
    $groups = [[]]; // OR of groups (NQ); each group = AND of clauses; each clause = OR of conditions
    $params = [];
    $order = [];

    foreach (explode('^', $encoded) as $token) {
        $token = trim($token);
        if ($token === '' || $token === 'EQ') {
            continue;
        }
        if (str_starts_with($token, 'NQ')) {
            $groups[] = [];
            $token = substr($token, 2);
        }
        if (str_starts_with($token, 'ORDERBYDESC')) {
            $f = substr($token, 11);
            if (isset($cols[$f])) {
                $order[] = col_expr($table, $f) . ' DESC';
            }
            continue;
        }
        if (str_starts_with($token, 'ORDERBY')) {
            $f = substr($token, 7);
            if (isset($cols[$f])) {
                $order[] = col_expr($table, $f) . ' ASC';
            }
            continue;
        }
        $isOr = false;
        if (str_starts_with($token, 'OR')) {
            $isOr = true;
            $token = substr($token, 2);
        }
        $cond = parse_condition($table, $token, $cols, $params);
        if ($cond === null) {
            continue;
        }
        $g = count($groups) - 1;
        if ($isOr && $groups[$g]) {
            $groups[$g][count($groups[$g]) - 1][] = $cond;
        } else {
            $groups[$g][] = [$cond];
        }
    }

    $groupSql = [];
    foreach ($groups as $clauses) {
        if (!$clauses) {
            continue;
        }
        $groupSql[] = '(' . implode(' AND ', array_map(fn($ors) => '(' . implode(' OR ', $ors) . ')', $clauses)) . ')';
    }
    return [implode(' OR ', $groupSql), $params, implode(', ', $order)];
}

function col_expr(string $table, string $field): string
{
    return field_def($table, $field)['type'] === 'integer' ? 'CAST(' . qi($field) . ' AS INTEGER)' : qi($field);
}

function parse_condition(string $table, string $token, array $cols, array &$params): ?string
{
    if (!preg_match('/^([a-z0-9_]+)(.*)$/s', $token, $m) || !isset($cols[$m[1]])) {
        return null;
    }
    [$field, $rest] = [$m[1], $m[2]];
    $op = null;
    foreach (QUERY_OPERATORS as $candidate) {
        if (str_starts_with($rest, $candidate)) {
            $op = $candidate;
            break;
        }
    }
    if ($op === null) {
        return null;
    }
    $value = substr($rest, strlen($op));
    $col = qi($field);
    $expr = col_expr($table, $field);
    $isInt = field_def($table, $field)['type'] === 'integer';
    $bind = function ($v) use (&$params, $isInt) {
        $params[] = $isInt ? (int)$v : $v;
        return '?';
    };

    switch ($op) {
        case 'ISEMPTY':
            return "($col IS NULL OR $col = '')";
        case 'ISNOTEMPTY':
            return "($col IS NOT NULL AND $col != '')";
        case '=':
            return $value === '' ? "($col IS NULL OR $col = '')" : "$expr = " . $bind($value);
        case '!=':
            return "($col IS NULL OR $expr != " . $bind($value) . ')';
        case 'IN':
        case 'NOT IN':
            $items = array_map('trim', explode(',', $value));
            $in = implode(',', array_map($bind, $items));
            return $op === 'IN' ? "$expr IN ($in)" : "($col IS NULL OR $expr NOT IN ($in))";
        case 'LIKE':
            return "$col LIKE " . $bind('%' . $value . '%');
        case 'NOT LIKE':
            return "($col IS NULL OR $col NOT LIKE " . $bind('%' . $value . '%') . ')';
        case 'STARTSWITH':
            return "$col LIKE " . $bind($value . '%');
        case 'ENDSWITH':
            return "$col LIKE " . $bind('%' . $value);
        default: // > >= < <=
            return "$expr $op " . $bind($value);
    }
}
