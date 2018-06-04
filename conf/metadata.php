<?php
/**
 * Options for the sentry plugin
 *
 * @author Andreas Gohr <gohr@cosmocode.de>
 */


$meta['dsn'] = array('string');
$meta['env'] = array('string');
$meta['errors'] = array('\\dokuwiki\\plugin\\sentry\\conf\\Setting');

