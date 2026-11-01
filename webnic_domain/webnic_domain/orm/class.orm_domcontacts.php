<?php

namespace Domain\webnic_domain\ORM;

use Illuminate\Database\Eloquent\Model as ORM_Model;

/**
 * Domain contacts saved locally before/after "Register".
 *
 * Shared storage table `hb_domain_contacts` (see sql.sql). This ORM is the
 * webnic_domain module's own copy; it does not reference any other module.
 *
 * Class ORM_domcontacts
 * @package Domain\webnic_domain\ORM
 */
class ORM_domcontacts extends ORM_Model
{
    /**
     * @var string
     */
    protected $table = 'hb_domain_contacts';

    /**
     * Mass-assignable? Everything is set explicitly in the module, so guard nothing.
     * @var array
     */
    protected $guarded = [];
}
