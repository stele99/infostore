<?php

/**  Objektklasse 
 * @var $attr Attributes of the current object
 */
class m_user extends dbobject
{
    /** Initis the Object
     * @param string $id (uid des Objektes)
     */
    public function __construct($id = "")
    {
        $this->setTable("users", "id", "", "ts_created");
        parent::__construct($id);
    }

    public function checkLogin($userId, $pw)
    {
        $ret = $this->select(["userid" => $userId]);
        if (count($ret) > 0) {
            $pwHash = $ret[0]["pwhash"];
            if (password_verify($pw, $pwHash)) {
                return $ret[0]["verification"];
            } else {
                return 403;
            }
        }
        return 404;
    }

    public function create($userid, $verification, $pw)
    {
        $pw = password_hash($pw, PASSWORD_DEFAULT);
        $this->attr["id"] = "";
        $this->attr["userid"] = $userid;
        $this->attr["verification"] = $verification;
        $this->attr["pwhash"] = $pw;
        $this->save();
    }
}
