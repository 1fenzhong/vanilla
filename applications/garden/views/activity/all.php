<?php if (!defined('APPLICATION')) exit(); ?>
<h1><?php echo Gdn::Translate('Recent Activity'); ?></h1>
<?php
if ($this->ActivityData->NumRows() > 0) {
   echo '<ul class="Activities">';
   include($this->FetchViewLocation('activities', 'activity', 'garden'));
   echo '</ul>';
} else {
   ?>
<div class="Info EmptyInfo"><?php echo Gdn::Translate('No records.', 'Not much happening here, yet.'); ?></div>
   <?php
}
