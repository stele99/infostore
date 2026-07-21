<?php

/**  Objektklasse 
 * @var $attr Attributes of the current object
 */
class m_data extends dbobject
{
    /** Initis the Object
     * @param string $id (uid des Objektes)
     */
    public function __construct($id = "")
    {
        $this->setTable("data", "id", "ts_changed", "ts_created");
        parent::__construct($id);
    }

    public function loadFromUid($uid = "")
    {
        $ret = $this->select(["uid" => $uid]);

        if (count($ret) > 0) {
            $this->initObject($ret[0]["id"]);
        } else {
            $this->attr["uid"]  = $uid;
        }
    }

    public function getlist($userId)
    {
        $ret = $this->select(["userid" => $userId], ["ts_changed" => "DESC", "ts_created" => "DESC"]);
        return $ret;
    }
}
