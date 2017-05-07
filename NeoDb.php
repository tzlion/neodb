<?php

namespace TzLion\NeoDb;

class NeoDb
{
    const F_ALL = "all";
    const F_ROW = "row";
    const F_INDEX = "index";
    const F_ONE = "one";
    const F_COLUMN = "column";
    const F_PAIRS = "pairs";

    private $dbh;
    private $driver;

    private $queryCount;
    private $printQueries;

    public function __construct($host, $username, $password, $dbName, $charset = "utf8mb4", $debugPrintQueries = false)
    {
        $this->driver = new \mysqli_driver();
        $this->driver->report_mode = MYSQLI_REPORT_ALL &~ MYSQLI_REPORT_INDEX;

        $this->dbh = new \mysqli($host, $username, $password, $dbName);
        $this->dbh->query("SET NAMES $charset");

        $this->printQueries = $debugPrintQueries;
    }

    public function query($query, $vars = [])
    {
        $sql = $this->subVars($query, $vars);
        $this->dbh->query($sql);
        $this->logQuery($sql);

        return $this->dbh->affected_rows;
    }

    public function fetch($mode, $query, $vars = [])
    {
        $sql = $this->subVars($query, $vars);
        $res = $this->dbh->query($sql);
        $this->logQuery($sql);

        if (!$res) {
            return null;
        }

        $result = null;
        switch($mode) {
            case self::F_ALL: // get everything
                while($row = $res->fetch_assoc()) $result[] = $row;
                return $result;
            case self::F_ROW: // get 1 row
                return $res->fetch_assoc() ?: null;
            case self::F_INDEX: // get everything indexed by the first column
                while($row = $res->fetch_assoc()) {
                    $index = array_shift($row);
                    $result[$index] = $row;
                }
                return $result;
            case self::F_ONE: // get one thing
                $row = $res->fetch_assoc();
                return $row ? array_shift($row) : null;
            case self::F_COLUMN: // get the first column
                while($row = $res->fetch_assoc()) {
                    $result[] = array_shift($row);
                }
                return $result;
            case self::F_PAIRS: // get the second column indexed by the first
                while($row = $res->fetch_assoc()) {
                    $index = array_shift($row);
                    $result[$index] = array_shift($row);
                }
                return $result;
            default:
                throw new \Exception('thats not a fetch mode (use NeoDb::F_* constants)');
        }
    }

    // Table name and col names are not escaped just fyi
    public function insert($table, $values)
    {
        if ( !is_array(reset($values)) ) { // is this an array of arrays? if not lets make it one
            $values = [$values];
        }

        $keys = array_keys(reset($values));
        $keynames = "`" . implode("`,`",$keys) . "`";

        $qvals = $allvars = [];
        foreach( $values as $pos => $subval ) {
            if ( array_keys($subval) != $keys ) {
                throw new \Exception("Value set at position $pos has non-matching keys!!");
            }
            $questionmarks = [];
            foreach ( $subval as $value ) {
                $allvars[] = $value;
                $questionmarks[] = "?";
            }
            $qvals[] = "(" . implode(",", $questionmarks) . ")";
        }

        $qvstring = implode( ",", $qvals );
        $query = "INSERT INTO `$table` ($keynames) VALUES $qvstring";

        return $this->query( $query, $allvars );
    }

    // Table name and col names are not escaped just fyi
    public function update($table, $data, $cond, $condVars = [])
    {
        $qparts = $qvals = [];
        foreach( $data as $key => $val ) {
            $qparts[] = "`$key` = ?";
            $qvals[] = $val;
        }

        $query = "UPDATE `$table` SET " . implode( ",", $qparts ) . " WHERE " . $cond;
        $qvals = array_merge( $qvals, $condVars );
        return $this->query( $query, $qvals );
    }

    // fakey bound parameters
    private function subVars($query, $vars = [])
    {
        if (!is_array($vars)) {
            $vars = [$vars];
        }

        $queryParts = explode( "?", $query );
        if ( count( $queryParts ) -1 != count ( $vars ) ) {
            throw new \Exception( "Number of variables supplied does not match number of ?s in the query!" );
        }

        $finalQuery = $queryParts[0];
        $queryPartNo = 0;
        foreach ( $vars as $var ) {
            $var = $this->escapifyVariable($var);
            $queryPartNo++;
            $finalQuery .= $var . $queryParts[$queryPartNo];
        }

        return $finalQuery;
    }

    private function escapifyVariable( $var )
    {
        if ( is_array( $var ) || is_object( $var ) ) {
            throw new \Exception ( "Variable was an array or object" );
        } else if ( is_bool( $var ) ) {
            return (int)$var;
        } else if ($var === null) {
            return "NULL";
        } else if ( is_numeric( $var ) && !is_string( $var ) ) {
            return $var; // lookin good
        } else { // assume string
            return "'" . $this->dbh->real_escape_string($var) . "'";
        }
    }

    public function getQueryCount()
    {
        return $this->queryCount;
    }

    private function logQuery($query)
    {
        $this->queryCount++;

        if ($this->printQueries) {
            var_dump($query);
        }
    }
}
