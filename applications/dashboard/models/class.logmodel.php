<?php if (!defined('APPLICATION')) exit();
/**
 * @copyright Copyright 2008, 2009 Vanilla Forums Inc.
 * @license http://www.opensource.org/licenses/gpl-2.0.php GPLv2
 */

class LogModel extends Gdn_Pluggable {
   /// PROPERTIES ///

   protected $_RecalcIDs = array('Discussion' => array());

   /// METHODS ///

   public function Delete($LogIDs) {
      if (!is_array($LogIDs))
         $LogIDs = explode(',', $LogIDs);
      Gdn::SQL()->WhereIn('LogID', $LogIDs)->Delete('Log');
   }

   // Format the content of a log file.
   public function FormatContent($Log) {
      $Data = $Log['Data'];

      // TODO: Check for a custom log type handler.

      switch ($Log['RecordType']) {
         case 'Discussion':
            $Result =
               '<b>'.$this->FormatKey('Name', $Data).'</b><br />'.
               $this->FormatKey('Body', $Data);
            break;
         case 'Comment':
            $Result = $this->FormatKey('Body', $Data);
            break;
         case 'User':
            $Result = $this->FormatRecord(array('Email', 'Name', 'DiscoveryText'), $Data);
            break;
         default:
            $Result = '';
      }
      return $Result;
   }

   public function FormatKey($Key, $Data) {
      if (isset($Data['_New']) && isset($Data['_New'][$Key])) {
         $Old = Gdn_Format::Text(GetValue($Key, $Data, ''), FALSE);
         $New = Gdn_Format::Text($Data['_New'][$Key], FALSE);
         $Result = $this->FormatDiff($Old, $New);
      } else {
         $Result = Gdn_Format::Text(GetValue($Key, $Data, ''), FALSE);
      }
      return nl2br(trim(($Result)));
   }

   public function FormatRecord($Keys, $Data) {
      $Result = array();
      foreach ($Keys as $Key) {
         if (!GetValue($Key, $Data))
            continue;
         $Result[] = '<b>'.htmlspecialchars($Key).'</b>: '.htmlspecialchars(GetValue($Key, $Data));
      }
      $Result = implode('<br />', $Result);
      return $Result;
   }

   public function FormatDiff($Old, $New) {
      static $TinyDiff = NULL;

      if ($TinyDiff === NULL) {
         require_once(dirname(__FILE__).'/tiny_diff.php');
         $TinyDiff = new Tiny_diff();
      }
      
      $Result = $TinyDiff->compare($Old, $New, 'html');
      return $Result;
   }

   public function GetIDs($IDs) {
      if (is_string($IDs))
         $IDs = explode(',', $IDs);

      $Logs = Gdn::SQL()
         ->Select('*')
         ->From('Log')
         ->WhereIn('LogID', $IDs)
         ->Get()->ResultArray();
      foreach ($Logs as &$Log) {
         $Log['Data'] = unserialize($Log['Data']);
      }

      return $Logs;
   }

   public function GetWhere($Where = FALSE, $OrderFields = '', $OrderDirection = 'asc', $Offset = FALSE, $Limit = FALSE) {
      if ($Offset < 0)
         $Offset = 0;

      if (isset($Where['Operation'])) {
         Gdn::SQL()->WhereIn('Operation', (array)$Where['Operation']);
         unset($Where['Operation']);
      }

      $Result = Gdn::SQL()
         ->Select('l.*')
         ->Select('ru.Name as RecordName, iu.Name as InsertName')
         ->From('Log l')
         ->Join('User ru', 'l.RecordUserID = ru.UserID', 'left')
         ->Join('User iu', 'l.InsertUserID = iu.UserID', 'left')
         ->Where($Where)
         ->Limit($Limit, $Offset)
         ->OrderBy($OrderFields, $OrderDirection)
         ->Get()->ResultArray();

      // Deserialize the data.
      foreach ($Result as &$Row) {
         $Row['Data'] = unserialize($Row['Data']);
      }

      return $Result;
   }

   public function GetCountWhere($Where) {
      if (isset($Where['Operation'])) {
         Gdn::SQL()->WhereIn('Operation', (array)$Where['Operation']);
         unset($Where['Operation']);
      }

      $Result = Gdn::SQL()
         ->Select('l.LogID', 'count', 'CountLogID')
         ->From('Log l')
         ->Where($Where)
         ->Get()->Value('CountLogID', 0);

      return $Result;
   }

   /**
    * Log an operation into the log table.
    *
    * @param string $Operation The operation being performed. This is usually one of:
    *  - Delete: The record has been deleted.
    *  - Edit: The record has been edited.
    *  - Spam: The record has been marked spam.
    *  - Moderate: The record requires moderation.
    * @param string $RecordType The type of record being logged. This usually correspond to the tablename of the record.
    * @param array $Data The record data.
    *  - If you are logging just one row then pass the row as an array.
    *  - You can pass an additional _New element to tell the logger what the new data is.
    * @return int The log id.
    */
   public static function Insert($Operation, $RecordType, $Data) {
      // Check to see if we are storing two versions of the data.
      if (($InsertUserID = self::_LogValue($Data, 'Log_InsertUserID')) === NULL) {
         $InsertUserID = Gdn::Session()->UserID;
      }
      // Do some known translations for the parent record ID.
      if (($ParentRecordID = self::_LogValue($Data, 'ParentRecordID')) === NULL) {
         switch ($RecordType) {
            case 'Activity':
               $ParentRecordID = self::_LogValue($Data, 'CommentActivityID', 'CommentActivityID');
               break;
            case 'Comment':
               $ParentRecordID = self::_LogValue($Data, 'DiscussionID', 'DiscussionID');
               break;
         }
      }

      // Get the row information from the data or determine it based on the type.
      $LogRow = array(
          'Operation' => $Operation,
          'RecordType' => $RecordType,
          'RecordID' => self::_LogValue($Data, 'RecordID', $RecordType.'ID'),
          'RecordUserID' => self::_LogValue($Data, 'RecordUserID', 'UpdateUserID', 'InsertUserID'),
          'RecordDate' => self::_LogValue($Data, 'RecordDate', 'DateUpdated', 'DateInserted'),
          'InsertUserID' => $InsertUserID,
          'DateInserted' => Gdn_Format::ToDateTime(),
          'ParentRecordID' => $ParentRecordID,
          'Data' => serialize($Data)
      );
      if ($LogRow['RecordDate'] == NULL)
         $LogRow['RecordDate'] = Gdn_Format::ToDateTime();

      // Insert the log entry.
      $LogID = Gdn::SQL()->Insert('Log', $LogRow);
      return $LogID;
   }

   public static function LogChange($Operation, $RecordType, $NewData) {
      $RecordID = isset($NewData['RecordID']) ? $NewData['RecordID'] : $NewData[$RecordType.'ID'];

      // Grab the record from the DB.
      $OldData = Gdn::SQL()->GetWhere($RecordType, array($RecordType.'ID' => $RecordID))->ResultArray();
      foreach ($OldData as $Row) {
         $Row['_New'] = $NewData;
         self::Insert($Operation, $RecordType, $Row);
      }
   }

   protected static function _LogValue($Data, $LogKey, $BakKey1 = '', $BakKey2 = '') {
      if (isset($Data[$LogKey]) && $LogKey != $BakKey1) {
         $Result = $Data[$LogKey];
         unset($Data[$LogKey]);
      } elseif (isset($Data['_New'][$BakKey1])) {
         $Result = $Data['_New'][$BakKey1];
      } elseif (isset($Data[$BakKey1]) && ($Data[$BakKey1] || !$BakKey2)) {
         $Result = $Data[$BakKey1];
      } elseif (isset($Data[$BakKey2])) {
         $Result = $Data[$BakKey2];
      } else {
         $Result = NULL;
      }

      return $Result;
   }

   public function Recalculate() {
      $DiscussionIDs = $this->_RecalcIDs['Discussion'];
      if (count($DiscussionIDs) == 0)
         return;

      $In = implode(',', array_keys($DiscussionIDs));
      $Px = Gdn::Database()->DatabasePrefix;
      $Sql = "update {$Px}Discussion d set d.CountComments = (select coalesce(count(c.CommentID), 0) + 1 from {$Px}Comment c where c.DiscussionID = d.DiscussionID) where d.DiscussionID in ($In)";
      Gdn::Database()->Query($Sql);

      $this->_RecalcIDs['Discussion'] = array();
   }

   public function Restore($Log, $DeleteLog = TRUE) {
      static $Columns = array();

      if (is_numeric($Log)) {
         // Grab the log.
         $LogID = $Log;
         $Log = Gdn::SQL()->GetWhere('Log', array('LogID' => $LogID))->FirstRow(DATASET_TYPE_ARRAY);
         if (!$Log) {
            throw NotFoundException('Log');
         }
      }

      // Throw an event to see if the restore is being overridden.
      $this->EventArguments['Log'] =& $Log;
      $this->FireEvent('BeforeRestore');
      if (GetValue('Overridden', $Log))
         return; // a plugin handled the restore.

      // Keep track of a discussion ID so that it's count can be recalculated.
      if ($Log['Operation'] != 'Edit') {
         switch ($Log['RecordType']) {
            case 'Discussion':
               $this->_RecalcIDs['Discussion'][$Log['RecordID']] = TRUE;
               break;
            case 'Comment':
               $this->_RecalcIDs['Discussion'][$Log['ParentRecordID']] = TRUE;
               break;
         }
      }


      $Data = $Log['Data'];
      if (!isset($Columns[$Log['RecordType']])) {
         $Columns[$Log['RecordType']] = Gdn::SQL()->FetchColumns($Log['RecordType']);
      }
      
      $Set = array_flip($Columns[$Log['RecordType']]);
      // Set the sets from the data.
      foreach ($Set as $Key => $Value) {
         if (isset($Data[$Key]))
            $Set[$Key] = $Data[$Key];
         else
            unset($Set[$Key]);
      }

      switch ($Log['Operation']) {
         case 'Edit':
            // We are restoring an edit so just update the record.
            $IDColumn = $Log['RecordType'].'ID';
            $Where = array($IDColumn => $Log['RecordID']);
            unset($Set[$IDColumn]);
            Gdn::SQL()->Put(
               $Log['RecordType'],
               $Set,
               $Where);

            break;
         case 'Delete':
         case 'Spam':
         case 'Moderate':
            $IDColumn = $Log['RecordType'].'ID';
            
            if (!$Log['RecordID']) {
               // This log entry was never in the table.
               unset($Set[$IDColumn]);
               if (isset($Set['DateInserted'])) {
                  $Set['DateInserted'] = Gdn_Format::ToDateTime();
               }
            }

            // Insert the record back into the db.
            Gdn::SQL()
               ->Options('Ignore', TRUE)
               ->Insert($Log['RecordType'], $Set);
            break;
      }

      if ($DeleteLog)
         Gdn::SQL()->Delete('Log', array('LogID' => $Log['LogID']));
   }
}