<?php
/**
 * Options for the sentry plugin
 *
 * @author Andreas Gohr, Michael Große <dokuwiki@cosmocode.de>
 */


$meta['dsn'] = array('string');
$meta['env'] = array('string');
$meta['errors'] = array('\\dokuwiki\\plugin\\sentry\\conf\\Setting');

