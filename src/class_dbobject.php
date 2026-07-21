<?php

/** DB Oeration Klasse um Objekte zu lesen.
 * @var $attr Attributes of the current object
 * Klasse muss von Sub-Klasse verwendet werden.
 * Im Konstruktor der Sub-Klasse werden zuerst die Daten mit setTable gesetzt
 *
 * Klasse verschlüsselt und entschlüsselt Felder die mit c_ enden.
 * Key wird aus Session ($_SESSION["c_key"] und iv aus Session ($_SESSION["user_iv"]) ODER! tabellenfeld iv gelesen.
 */

$db_connection = false;
$db_pdo = "";
$ce_key = false;
class dbobject
{
    private $table;
    private $tablekey;
    private $tablefield_changed;
    private $tablefield_created;
    private $tablefield_deleted;

    private $fieldlist;
    private $dbkeyfield;
    public $attr;
    public $attr_old;
    public $changeLog; // Change protokollierung aktivieren.

    protected $db;
    protected $c_key;

    /** Initis the Object
     * @param string $user (UID of user or Email-Adresse)
     */
    public function __construct($id = "", $pdo = "")
    {
        global $C;
        global $db_connection;
        global $db_pdo;
        global $ce_key;

        $pdo = (empty($pdo)) ? $C["PDO"] : $pdo;
        if (!$db_connection || $db_pdo != $pdo) {
            $db_connection = new \PDO($pdo, $C["PDO_USER"], $C["PDO_PWD"]);
            $db_pdo = $pdo;
        }

        /** c-key Encryption Key for Database load (all Fields with _c will be encrypted) */
        if (!$ce_key) {
            $fn = ROOTPATH . "/inc/c.key";
            if (file_exists($fn)) {
                $ce_key = hex2bin(trim(file_get_contents($C["CEK"])));
            }
        }
        $this->c_key = $ce_key;

        $this->db = $db_connection;
        $this->initObject($id);
    }

    /** Function to set table and keyfield etc.
     * @param $table Datbasetablename
     * @param $tablekey Keyfield of table
     * @param $tablefield_changed tablefield for change timestamp
     * @param $tablefield_created tablefield for created timestamp
     * @param $tablefield_deleted tablefield for deleted flag
     */
    public function setTable($table, $tablekey, $tablefield_changed = "", $tablefield_created = "", $tablefield_deleted = "")
    {
        $this->table      = $table;
        $this->tablekey   = $tablekey;
        $this->tablefield_deleted = $tablefield_deleted;
        $this->tablefield_changed = $tablefield_changed;
        $this->tablefield_created = $tablefield_created;
    }

    /** Tabelle zurückgeben
     */
    public function getTableName()
    {
        return $this->table;
    }

    /** reads the Object from Database
     * @param $string ID of the dataset / Object
     */
    protected function initObject($id)
    {
        if (!empty($id)) {
            if (strpos($id, "@")) {
                // Search by EMail
                $sql = "SELECT * FROM " . $this->table . " WHERE email = :id";
            } else {
                // Search by UID
                $sql = "SELECT * FROM " . $this->table . " WHERE " . $this->tablekey . " = :id";
            }

            $stm = $this->db->prepare($sql);
            $stm->bindParam(":id", $id);
            $stm->execute();

            if ($stm) {
                $this->attr = $stm->fetch(\PDO::FETCH_ASSOC);
                $this->attr = $this->decryptrow($this->attr);
                $this->attr_old = $this->attr;
            } else {
                throw new \ErrorException("SQL Execution Error: " . implode(", ", $this->db->errorInfo()) . ". ");
            }
        } else {
            $this->getFields(true);
            return false;
        }
    }
    /** Reads the object from Database
     * @param $id Keyfield value for lookup
     * @return Array of found Entries or false
     */
    public function readObject($id)
    {
        $this->initObject($id);
        return $this->attr;
    }

    /** Activate ChangeLog  */
    public function ChangeLogActivate()
    {
        $this->changeLog = true;
    }

    /** Saves the current object
     */
    public function save()
    {

        if (empty($this->attr)) {
            return false;
        }
        if (empty($this->fieldlist)) {
            $this->getFields();
        }

        #print_r($this->fieldlist);
        // Prüfe Attributsliste
        $insert = (empty($this->attr[$this->dbkeyfield])) ? true : false;
        if ($insert && empty($this->attr[$this->tablekey]) && $this->fieldlist[$this->dbkeyfield]["autoinc"] != 1 && $this->dbkeyfield != "id") {
            throw new \ErrorException("DB Insert, but tablekey [" . $this->tablekey . "] empty! ");
        }

        if ($this->dbkeyfield != "id") {
            //prüfen ob Insert!
            $sql = "SELECT " . $this->dbkeyfield . " FROM " . $this->table . " WHERE " . $this->dbkeyfield . " = '" . $this->attr["$this->dbkeyfield"] . "'";
            $ret = $this->sql($sql);
            if (count($ret) < 1) $insert = true;
        }

        $ins1 = "";
        $ins2 = "";
        $upd = "";
        $iv = (!empty($this->attr["iv"])) ? $this->attr["iv"] : "";
        if ($insert && !empty($this->tablefield_created)) {
            $this->attr[$this->tablefield_created] = getTimestamp();
        }
        if (!$insert && !empty($this->tablefield_changed)) {
            $this->attr[$this->tablefield_changed] = getTimestamp();
        }

        foreach ($this->attr as $key => $value) {
            if (!array_key_exists($key, $this->fieldlist)) {
                throw new \ErrorException("Attribute '$key' not in fieldlist of " . $this->table);
            }

            /* Umwandeln wird hier nicht mehr durchgeführt, muss hier schon korrekt vorliegen! erfolgt durch controller(!)
            if (
            strpos(strtolower($this->fieldlist[$key]["type"]), "decimal")  !== false
            || strpos(strtolower($this->fieldlist[$key]["type"]), "number")  !== false
            ) {
            $value = NumberUI2DB($value);
            $this->attr[$key] = $value;
            }
             */

            // Verschlüsseln
            if (substr($key, strlen($key) - 2) == "_c") {
                $value = $this->crypt($value, $iv);
            }

            $value = $this->db->quote($value);
            if ($insert) {
                if ($this->fieldlist[$key]["autoinc"] != 1) {
                    $ins1 .= $key . ", ";
                    $ins2 .= "$value, ";
                }
            } else {
                $upd .= "$key = $value, ";
            }
        }
        if ($insert) {
            $ins1 = substr($ins1, 0, strlen($ins1) - 2);
            $ins2 = substr($ins2, 0, strlen($ins2) - 2);
            $sql = "INSERT INTO " . $this->table . " ($ins1) VALUES ($ins2);";
        } else {
            $upd = substr($upd, 0, strlen($upd) - 2);
            $sql = "UPDATE " . $this->table . " SET " . $upd . " WHERE " . $this->tablekey . "= '" . $this->attr[$this->tablekey] . "'";
            if ($this->changeLog) {
                $this->writeChangeLog("UPDATE");
            }
        }

        try {
            $ret = $this->db->exec($sql);
            $errcode = $this->db->errorInfo();
            if ($insert && $this->dbkeyfield == "id") {
                $this->attr[$this->dbkeyfield] = $this->db->lastInsertId();
            }

            if (!empty($errcode[2])) {
                throw new \ErrorException("SQL Execution Error: " . implode(", ", $this->db->errorInfo()) . ". ");
            }

            return true;
        } catch (
            \PDOException $Exception
        ) {
            throw new \Exception($Exception->getMessage(), (int) $Exception->getCode());
        }

        $this->readObject($this->attr[$this->tablekey]);
    }

    /** Löscht den aktuellen Eintrag aus $this->attr oder einer angegebenen ID
     * @param string $id ID des zulöschenden Datensatzes (keyfield), Alternativ aktueller Datensazu im Speicher.
     *
     */
    public function delete($id = "")
    {
        if ($id == "") {
            $id = $this->attr[$this->tablekey];
        }
        if (empty($this->tablefield_deleted)) {
            $sql = "DELETE FROM  " . $this->table . " WHERE " . $this->tablekey . " = '" . $id . "'";
        } else {
            $sql = "UPDATE " . $this->table . " SET " . $this->tablefield_deleted . " = 1 WHERE " . $this->tablekey . "= '" . $id . "'";
        }
        $ret = $this->db->query($sql);
        if ($ret) {
            if ($this->changeLog) {
                $this->writeChangeLog("DELETE");
            }

            $this->attr = false;
            return true;
        } else {
            throw new \ErrorException("SQL Execution Error: " . implode(", ", $this->db->errorInfo()) . ". ");
            return false;
        };
    }

    /** Liefert die Felder zurück die in der Datenbank gespeichert sind
     * @param boolean $setAttr if Set, attr array will be initialized.
     */
    public function getFields($setAttr = false)
    {
        global $C;
        $fields = [];

        if ($C["DB"] == "sqlite") {
            $sql = "pragma table_info(" . $this->table . ")";
            $fCol  = "name";
            $tCol  = "type";
        } else {
            $sql = "DESCRIBE " . $this->table;
            $fCol = "Field";
            $tCol = "Type";
        }

        $stm = $this->db->query($sql);
        $ret = $stm->fetchAll(\PDO::FETCH_ASSOC);
        if ($setAttr) {
            $this->attr = array();
        }


        foreach ($ret as $row) {
            if ($C["DB"] == "sqlite") {
                $autoinc = ($row["name"] == "id" && $row["pk"]  == 1) ? true : false;
            } else {
                $autoinc = (strpos(", " . strtolower($row["Extra"]), "auto_increment")) ? true : false;
            }
            $fields[$row[$fCol]]["autoinc"]  = $autoinc;
            $fields[$row[$fCol]]["type"]     = $row[$tCol];
            if ($autoinc) {
                $this->dbkeyfield = $row[$fCol];
            }

            if ($setAttr) {
                $this->attr[$row[$fCol]] = null;
            }
        }
        if (empty($this->dbkeyfield)) $this->dbkeyfield = $this->tablekey;
        $this->fieldlist = $fields;
        return $fields;
    }
    /** Manuelles setzen der Feldliste
     * kann notwendig sein, bei Massenverarbeitungen
     * @param array $flds Feldliste als Array (KEY=>TYPE). Bsp: ["fldname" => "decimal"]
     */
    public function fieldlistManualSet($flds)
    {
        $this->fieldlist = $flds;
    }

    /**
     * Crypt a value
     * @param string $val Value to decrypt
     * @param string $giv force IV to use instead of session iv
     */
    private function crypt($val, $giv = "")
    {
        global $_SESSION;

        if (empty($val)) {
            return $val;
        }

        // Block muss minimum 20 Zeichen haben
        $val = (strlen($val) < 21) ? str_pad($val, 20, ' ') : $val;

        $iv = @$_SESSION["user_iv"];
        if (!empty($giv)) {
            $iv = $giv;
        }

        $iv = hex2bin($iv);
        if (empty($this->c_key) || empty($iv)) {
            throw new \Exception("ERROR decrypt: iv ($iv) or key($this->c_key) empty!");
        }

        $c = openssl_encrypt($val, 'aes-256-xts', $this->c_key, 0, $iv);
        return ($c);
    }

    /**
     * decrypt a value
     * @param string $val Value to decrypt
     * @param string $giv force IV to use instead of session iv
     */
    private function decrypt($val, $giv = "")
    {
        global $_SESSION;
        if (empty($val)) {
            return $val;
        }

        $iv = @$_SESSION["user_iv"];
        if (!empty($giv)) {
            $iv = $giv;
        }

        $iv = hex2bin($iv);
        if (empty($this->c_key) || empty($iv)) {
            throw new \Exception("ERROR decrypt: iv ($iv) or key($this->c_key) empty!");
        }

        $d = trim(openssl_decrypt($val, 'aes-256-xts', $this->c_key, 0, $iv));
        return ($d);
    }

    /** Decrypt Associative Array complete (on _c fields)
     *  @param array $row Associative Array to decrypt
     */
    private function decryptrow($row)
    {
        if (!is_array($row)) {
            return $row;
        }
        $iv = (!empty(($row["iv"]))) ? $row["iv"] : "";
        foreach ($row as $key => $val) {
            if (substr($key, strlen($key) - 2) == "_c") {
                $row[$key] = $this->decrypt($val, $iv);
            }
        }
        return ($row);
    }

    /** Simle WHERE Search of table by field or fields (OR)
     * @param string $s Searchstring
     * @param mixed $field Field or array of fields to search in
     * @param integer $limit Limit Treffer
     * @param integer $start Limit Start
     */
    public function simpleSearch($s, $field, $limit = 999, $start = 0)
    {
        $s = str_replace("*", "%", $s);
        if (is_array($field)) {
            $sql = "SELECT * FROM " . $this->table . " WHERE ";
            foreach ($field as $fld) {
                $sql .= $fld . " LIKE " . $this->db->quote($s) . " OR ";
            }
            $sql = substr($sql, 0, strlen($sql) - 3);
        } else {
            $sql = "SELECT * FROM " . $this->table . " WHERE $field LIKE " . $this->db->quote($s);
        }
        $sql .= " LIMIT $start, $limit";
        $stm = $this->db->prepare($sql);
        $stm->execute();
        if ($stm) {
            $result = $stm->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($result as $row) {
                $ret[] = $this->decryptrow($row);
            }
            return $ret;
        } else {
            throw new \ErrorException("SQL Execution Error: " . implode(", ", $this->db->errorInfo()) . ". ");
        }
    }
    /** Select Data from Table
     * @param array $criteria [{field} => {value}] (with * possible)
     * @param array $sort     [{field} => {ASC/DESC}]
     * @param integer $limit max amount default = 999
     * @param integer $start start with limit default = 0
     * @param array $fieldlist select only defined fields e.g["id", "name"]
     * @return array Search Results
     */
    public function select($criteria, $sort = [], $limit = 999, $start = 0, $fieldlist = [])
    {
        $flds = " * ";
        if (count($fieldlist) > 0) {
            $flds = implode(", ", $fieldlist);
        }
        if (empty($criteria)) {
            $sql = "SELECT $flds FROM " . $this->table;
        } else {
            $sql = "SELECT $flds FROM " . $this->table . " WHERE ";
            foreach ($criteria as $field => $val) {
                $operator = " = ";
                if (strpos(" " . $val, "*") > 0) {
                    $operator = " LIKE ";
                }
                if ($val === "") {
                    $sql .= "($field is null OR $field = '')" . " AND ";
                } else {
                    $sql .= $field . $operator . $this->db->quote($val) . " AND ";
                }
            }
            $sql = substr($sql, 0, strlen($sql) - 4);
        }

        if (count($sort) > 0) {
            $sql .= " ORDER BY ";
            foreach ($sort as $field => $type) {
                $sql .= $field . " " . $type . ", ";
            }
            $sql = substr($sql, 0, strlen($sql) - 2);
        }

        $sql = $sql . " LIMIT $start, $limit";
        $stm = $this->db->prepare($sql);
        if (!$stm) {
            throw new \ErrorException("SQL Execution Error: " . implode(", ", $this->db->errorInfo()) . ". ");
        }

        $stm->execute();
        $ret = [];
        if ($stm) {
            $result = $stm->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($result as $row) {
                $ret[] = $this->decryptrow($row);
            }
            return $ret;
        } else {
            throw new \ErrorException("SQL Execution Error: " . implode(", ", $this->db->errorInfo()) . ". ");
        }
    }

    public function sql($sql)
    {
        $stm = $this->db->prepare($sql);
        $stm->execute();
        $ret = [];
        if ($stm) {
            $result = $stm->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($result as $row) {
                $ret[] = $this->decryptrow($row);
            }
            return $ret;
        } else {
            throw new \ErrorException("SQL Execution Error: " . implode(", ", $this->db->errorInfo()) . ": $sql");
        }
    }
    /** log Changes on object
     *
     */
    public function writeChangeLog($action = "UPDATE")
    {
        global $_SESSION;
        $user_uid = @$_SESSION["user_uid"];
        $iv = (!empty($this->attr["iv"])) ? $this->attr["iv"] : "";
        $ts = getTimestamp();
        $sql = "";
        $fldNoChange = array("ts_created", "ts_change", "ts_changed", "ts", "id");
        foreach ($this->attr as $fieldname => $value) {
            if ($action == "DELETE" && $fieldname != $this->tablekey) {
                $this->attr[$fieldname] = "";
            }
            if ($this->attr_old[$fieldname] != $this->attr[$fieldname] && !in_array($fieldname, $fldNoChange)) {
                $val_old = $this->attr_old[$fieldname];
                $val_new = $this->attr[$fieldname];
                if (substr($fieldname, strlen($fieldname) - 2) == "_c") {
                    $val_old = $this->crypt($val_old, $iv);
                    $val_new = $this->crypt($val_new, $iv);
                }
                $sql .= "INSERT INTO changelog (tabname, tablekey, action, fieldname, old_value, new_value, user_uid, ts)
                VALUES (
                    '" . $this->table . "',
                    '" . $this->attr[$this->tablekey] . "',
                    '" . $action . "',
                    '" . $fieldname . "',
                    '" . $val_old . "',
                    '" . $val_new . "',
                    '" . $user_uid . "',
                    '" . $ts . "'
                   );";
            }
        }
        $stm = $this->db->prepare($sql);
        $stm->execute();
    }

    /**
     * Fulltext Search over specified fulltext field/index
     * @param string $s Searchterm
     * @param mixed $fld Field or array of fields to search in
     * @param integer $limit Limit Treffer
     * @param integer $start Limit Start
     */
    public function fulltextSearch($s, $field, $limit = 999, $start = 0)
    {
        if (is_array($field)) {
            $sql = "SELECT * FROM " . $this->table . " WHERE ";
            foreach ($field as $fld) {
                $sql .= "MATCH($fld) AGAINST('$s' IN BOOLEAN MODE) OR ";
            }
            $sql = substr($sql, 0, strlen($sql) - 3);
        } else {
            $sql = "SELECT * FROM " . $this->table . " WHERE MATCH('$field') AGAINST($s IN BOOLEAN MODE)";
        }
        $sql .= " LIMIT $start, $limit";
        $stm = $this->db->prepare($sql);
        $stm->execute();
        $ret = [];
        if ($stm) {
            $result = $stm->fetchAll(\PDO::FETCH_ASSOC);
            foreach ($result as $row) {
                $ret[] = $this->decryptrow($row);
            }
            return $ret;
        } else {
            throw new \ErrorException("SQL Execution Error: " . implode(", ", $this->db->errorInfo()) . ". ");
        }
    }
}
