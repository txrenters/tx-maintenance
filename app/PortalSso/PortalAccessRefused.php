<?php

namespace App\PortalSso;

use RuntimeException;

/**
 * Maintenance itself has not given this person a staff account; the portal cannot create one.
 */
class PortalAccessRefused extends RuntimeException {}
