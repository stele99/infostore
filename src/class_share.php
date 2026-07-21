<?php

/**  Objektklasse 
 * @var $attr Attributes of the current object
 */
class m_share extends dbobject
{
    /** Initis the Object
     * @param string $id (uid des Objektes)
     */
    public function __construct($id = "")
    {
        $this->setTable("shares", "id", "ts_created", "ts_created");
        parent::__construct($id);

        if (!is_numeric($id)) {
            // uid initialisierung
            $ret = $this->select(["uid" => $id]);
            if (count($ret) > 0) {
                $id = $ret["id"];
            }
            $this->initObject($id);
        }
    }

    public function create() {}

    public function getSharesByStoreId($storeId)
    {
        $ret = $this->select(["storeid" => $storeId]);
        if (count($ret) > 0) {
            return $ret;
        }
        return false;
    }
}
