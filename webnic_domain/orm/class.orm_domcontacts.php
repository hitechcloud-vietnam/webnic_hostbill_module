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
 *
 * @package   HiTechCloud WebNIC Suite
 * @author    HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @copyright 2026 HiTechCloud by Pho Tue SoftWare Solutions JSC
 * @license   MIT
 * @version   1.2026-11-02.2
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
