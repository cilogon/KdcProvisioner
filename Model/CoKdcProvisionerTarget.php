<?php
/**
 * COmanage Registry CO KDC Provisioner Target Model
 *
 * Portions licensed to the University Corporation for Advanced Internet
 * Development, Inc. ("UCAID") under one or more contributor license agreements.
 * See the NOTICE file distributed with this work for additional information
 * regarding copyright ownership.
 *
 * UCAID licenses this file to you under the Apache License, Version 2.0
 * (the "License"); you may not use this file except in compliance with the
 * License. You may obtain a copy of the License at:
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 * 
 * @link          http://www.internet2.edu/comanage COmanage Project
 * @package       registry-plugin
 * @since         COmanage Registry v4.1.0
 * @license       Apache License, Version 2.0 (http://www.apache.org/licenses/LICENSE-2.0)
 */

App::uses("CoProvisionerPluginTarget", "Model");

class CoKdcProvisionerTarget extends CoProvisionerPluginTarget {
  // Define class name for cake
  public $name = "CoKdcProvisionerTarget";

  // Add behaviors
  public $acsAs = array('Containable');

  // Association rules from this model to other models
  public $belongsTo = array(
    "CoProvisioningTarget",
    "Server"
  );

  // Default display field for cake generated views
  public $displayField = "server_id";
  
  // Request SQL servers
  public $cmServerType = ServerEnum::KdcServer;

  // Validation rules for table elements
  public $validate = array(
    'co_provisioning_target_id' => array(
      'rule' => 'numeric',
      'required' => true
    ),
    'server_id' => array(
      'content' => array(
        'rule' => 'numeric',
        'required' => true,
        'unfreeze' => 'CO'
      )
    ),
    'principal_type' => array(
      'content' => array(
        'rule' => array('validateExtendedType',
                        array('attribute' => 'Identifier.type',
                              'default' => array(IdentifierEnum::ePPN,
                                                 IdentifierEnum::ePTID,
                                                 IdentifierEnum::Mail,
                                                 IdentifierEnum::OIDCsub,
                                                 IdentifierEnum::OpenID,
                                                 IdentifierEnum::SamlPairwise,
                                                 IdentifierEnum::SamlSubject,
                                                 IdentifierEnum::UID))),
        'required' => true,
        'allowEmpty' => false
      )
    )
  );

  // Instance of KADM5 object
  private $kdc = null;

  /*
   * Delete or disable principal for a CO Person.
   *
   * @since  COmanage Registry v4.1.0
   * @param  Array CO Provisioning Target data
   * @param  Array Provisioning data, populated with ['CoPerson']
   * @return Boolean True on success
   *
   */

  private function deletePrincipal($coProvisioningTargetData, $provisioningData) {
    $principal = $this->getPrincipalFromProvisioningData($coProvisioningTargetData, $provisioningData);

    if(empty($principal)) {
      // Without a principal we cannot really do anything, but
      // since this is a delete action return true.
      return true;
    }

    // Get the KADM5Principal object from KDC.
    try {
      $principalObj = $this->getPrincipalObj($principal);
    } catch (Exception $e) {
      // Return false because we are unable to complete
      // a delete principal action.
      $msg = "deletePrincipal: Unable to query for KADM5Principal object: ";
      $msg = $msg . print_r($e->getMessage(), true);
      $this->log($msg);
      return false;
    }

    if(empty($principalObj)) {
      // No KADM5Principal object to delete.
      return true;
    }

    // Principal does exist in the KDC so we only take action
    // to change that if the record has a particular status.
    $currentAttributes = $principalObj->getAttributes();
    $principalIsDisabled = $currentAttributes & 64;

    if(!$principalIsDisabled) {
      try {
        // TODO Need to generalize this and make how deleting/disabling
        // is done a configurable option.
        //
        // DISALLOW_ALL_TIX is bit value 64 so we OR that with the current attribute value.
        $newAttributes = $currentAttributes | 64;
        $principalObj->setAttributes($newAttributes);
        $principalObj->save();
        $msg = "deletePrincipal: Deleted or disabled principal $principal";
        $this->log($msg);
      } catch (Exception $e) {
        $msg = "deletePrincipal: Unable to delete or disable principal: ";
        $msg = $msg . print_r($e->getMessage(), true);
        $this->log($msg);
        return false;
      }
    }

    return true;
  }


  /*
   * Get principal for CO Person from provisioning data
   *
   * @since  COmanage Registry v4.1.0
   * @param  Array CO Provisioning Target data
   * @param  Array Provisioning data, populated with ['CoPerson']
   * @return string Principal value or null if cannot be found
   *
   */

  private function getPrincipalFromProvisioningData($coProvisioningTargetData, $provisioningData) {
    // Search for the Identifier of the correct type 
    // that holds the value for the desired principal.
    $principalType = $coProvisioningTargetData['CoKdcProvisionerTarget']['principal_type'];

    $principal = null;
    if(!empty($provisioningData['Identifier'])){
      foreach($provisioningData['Identifier'] as $identifier) {
        if($identifier['type'] == $principalType) {
          $principal = $identifier['identifier'];
          break;
        }
      }
    }

    if(empty($principal)) {
      $coPersonId = $provisioningData['CoPerson']['id'];
      $msg = "getPrincipalFromProvisioningData: ";
      $msg = $msg . "Unable to find principal of type $principalType for CO Person ID $coPersonId";
      $this->log($msg);
    }

    return $principal;
  }

  /*
   * Get KADM5Principal object from KDC
   *
   * @since  COmanage Registry v4.1.0
   * @param  string principal 
   * @return string KADM5Principal object or null if does not exist
   *
   */

  private function getPrincipalObj($principal) {
    try {
      $principalObj = $this->kdc->getPrincipal($principal);
      $this->log("getPrincipalObj: Principal $principal exists in the KDC");
    } catch (Exception $e) {
      // Principal does not exist.
      $principalObj = null;
      $this->log("getPrincipalObj: Principal $principal does not exist in the KDC");
    }

    return $principalObj;
  }

  /*
   * Open connection to the KDC server and set propery to the 
   * KADM5 object.
   *
   * @since  COmanage Registry 4.1.0
   * @param  Array CO Provisioning Target data
   * @return void 
   * @throws RuntimeException if unable to connect to KDC
   *
   */

  private function openKdcConnection($coProvisioningTargetData) {
    // Open a connection to the KDC and query for the principal.
    $kdcServerId = $coProvisioningTargetData['CoKdcProvisionerTarget']['server_id'];

    try {
      $kdc = $this->CoProvisioningTarget->Co->Server->KdcServer->connect($kdcServerId);
    } catch (Exception $e) {
      $msg = "openKdcConnection: Unable to connect to KDC: ";
      $msg = $msg . print_r($e->getMessage(), true);
      $this->log($msg);

      throw new RuntimeException($msg);
    }

    $this->kdc = $kdc;
  }

  /**
   * Provision and manage a Kerberos principal for the specified CO Person.
   *
   * @since  COmanage Registry v4.1.0
   * @param  Array CO Provisioning Target data
   * @param  ProvisioningActionEnum Registry transaction type triggering provisioning
   * @param  Array Provisioning data, populated with ['CoPerson'] or ['CoGroup']
   * @return Boolean True on success
   */
  
  public function provision($coProvisioningTargetData, $op, $provisioningData) {
    // First determine what to do.
    $deletePrincipal = false;
    $syncPrincipal = false;
    
    switch($op) {
      case ProvisioningActionEnum::CoPersonAdded:
      case ProvisioningActionEnum::CoPersonEnteredGracePeriod:
      case ProvisioningActionEnum::CoPersonPetitionProvisioned:
      case ProvisioningActionEnum::CoPersonReprovisionRequested:
      case ProvisioningActionEnum::CoPersonPipelineProvisioned:
      case ProvisioningActionEnum::CoPersonUpdated:
      case ProvisioningActionEnum::CoPersonUnexpired:
        $syncPrincipal = true;
        break;

      case ProvisioningActionEnum::CoPersonExpired:
      case ProvisioningActionEnum::CoPersonDeleted:
        $deletePrincipal = true;
        break;

      default:
        // Ignore all other actions.
        return true;
        break;
    }

    // If we will be synchronizing or deleting a principal
    // open a connection to the KDC.
    if($syncPrincipal || $deletePrincipal) {
      try {
        $this->openKdcConnection($coProvisioningTargetData);
      } catch (Exception $e) {
        return false;
      }
    }

    if($syncPrincipal) {
      $this->syncPrincipal($coProvisioningTargetData, $provisioningData);
    }

    if($deletePrincipal) {
      $this->deletePrincipal($coProvisioningTargetData, $provisioningData);
    }
  }

  /**
   * Determine the provisioning status of this target.
   *
   * @since  COmanage Registry v4.1.0
   * @param  Integer $coProvisioningTargetId CO Provisioning Target ID
   * @param  Model   $Model                  Model being queried for status (eg: CoPerson, CoGroup,
   *                                         CoEmailList, COService)
   * @param  Integer $id                     $Model ID to check status for
   * @return Array ProvisioningStatusEnum, Timestamp of last update in epoch seconds, Comment
   * @throws InvalidArgumentException If $coPersonId not found
   * @throws RuntimeException For other errors
   */

  public function status($coProvisioningTargetId, $model, $id) {
    $ret = array();
    $ret['status'] = ProvisioningStatusEnum::NotProvisioned;
    $ret['timestamp'] = null;
    $ret['comment'] = "";

    // We only provision CoPerson records, not CoGroup or any model.
    if($model->name != 'CoPerson') {
      return $ret;
    }

    // Pull the provisioning target configuration.
    $args = array();
    $args['conditions']['CoKdcProvisionerTarget.co_provisioning_target_id'] = $coProvisioningTargetId;
    $args['contain'] = false;

    $coProvisioningTargetData = $this->find('first', $args);
    $principalType = $coProvisioningTargetData['CoKdcProvisionerTarget']['principal_type'];


    // Pull the CO Person record and find the Identifier of type principal_type 
    $args = array();
    $args['conditions']['CoPerson.id'] = $id;
    $args['contain'] = 'Identifier';

    $coPerson = $this->CoProvisioningTarget->Co->CoPerson->find('first', $args);

    $principal = null;
    foreach($coPerson['Identifier'] as $identifier) {
      if($identifier['type'] == $principalType) {
        $principal = $identifier['identifier'];
        break;
      }
    }

    // We cannot find the Identifier of the type principal_type so return unknown.
    if(empty($principal)) {
      $ret['status'] = ProvisioningStatusEnum::Unknown;
      $ret['comment'] = _txt('pl.kdcprovisioner.principal_type.not_found', array($principalType));

      $msg = "status: Cannot find Identifier of type $principalType for coPerson ID $id";
      $this->log($msg);

      return $ret;
    }

    // Open a connection to the KDC and query for the principal.
    try {
      $this->openKdcConnection($coProvisioningTargetData);
      $principalObj = $this->getPrincipalObj($principal);
    } catch (Exception $e) {
      $ret['status'] = ProvisioningStatusEnum::Unknown;
      return $ret;
    }

    if($principalObj) {
      $ret['status'] = ProvisioningStatusEnum::Provisioned;
      $ret['timestamp'] = $principalObj->getLastModificationDate();

      // TODO Need to generalize this and make how deleting/disabling
      // is done a configurable option.
      //
      // DISALLOW_ALL_TIX is bit value 64 so we OR that with the current attribute value.
      $currentAttributes = $principalObj->getAttributes();
      $principalIsDisabled = $currentAttributes & 64;

      if($principalIsDisabled) {
        $ret['comment'] = _txt('pl.kdcprovisioner.principal.disabled');
      }
    } 

    return $ret;
  }

  /*
   * Synchronize principal for a CO Person.
   *
   * @since COmanage Registry v4.1.0
   * @param  Array CO Provisioning Target data
   * @param  Array Provisioning data, populated with ['CoPerson']
   * @return Boolean True on success
   *
   */

  private function syncPrincipal($coProvisioningTargetData, $provisioningData) {
    $principal = $this->getPrincipalFromProvisioningData($coProvisioningTargetData, $provisioningData);

    if(empty($principal)) {
      // We cannot provision the principal without the Identifier
      // of the correct type so return false to indicate failure.
      return false;
    }

    $coPersonStatus = $provisioningData['CoPerson']['status'];

    // Get the KADM5Principal object from KDC.
    try {
      $principalObj = $this->getPrincipalObj($principal);
    } catch (Exception $e) {
      $msg = "syncPrincipal: Unable to query for KADM5Principal object: ";
      $msg = $msg . print_r($e->getMessage(), true);
      $this->log($msg);
      return false;
    }

    if(empty($principalObj)) {
      // Principal does not exist in the KDC so if the CO Person
      // record has an acceptable status try to create it in the KDC.

      switch($coPersonStatus) {
        case StatusEnum::Active:
        case StatusEnum::GracePeriod:
          // Create the principal.
          try {
            $principalObj = new KADM5Principal($principal);
            $this->kdc->createPrincipal($principalObj);
            $msg = "syncPrincipal: Created principal $principal";
            $this->log($msg);
          } catch (Exception $e) {
            $msg = "syncPrincipal: Unable to create principal $principal: ";
            $msg = $msg . print_r($e->getMessage(), true);
            $this->log($msg);
            return false;
          }
          break;
      }
    } else {
      // Principal does exist in the KDC so we only take action
      // to change that if the record has a particular status.
      $currentAttributes = $principalObj->getAttributes();
      $principalIsDisabled = $currentAttributes & 64;
      switch($coPersonStatus) {
        case StatusEnum::Deleted:
        case StatusEnum::Expired:
        case StatusEnum::Locked:
        case StatusEnum::Suspended:
          if(!$principalIsDisabled) {
            try {
              // TODO Need to generalize this and make how deleting/disabling
              // is done a configurable option.
              //
              // DISALLOW_ALL_TIX is bit value 64 so we OR that with the current attribute value.
              $newAttributes = $currentAttributes | 64;
              $principalObj->setAttributes($newAttributes);
              $principalObj->save();
              $msg = "syncPrincipal: Deleted or disabled principal $principal";
              $this->log($msg);
            } catch (Exception $e) {
              $msg = "syncPrincipal: Unable to delete or disable principal: ";
              $msg = $msg . print_r($e->getMessage(), true);
              $this->log($msg);
              return false;
            }
          } else {
            $msg = "syncPrincipal: Principal $principal is already disabled";
            $this->log($msg);
          }
          break;

        case StatusEnum::Active:
        case StatusEnum::GracePeriod:
          if($principalIsDisabled) {
            try {
              // TODO Need to generalize this and make how deleting/disabling
              // is done a configurable option.
              //
              // DISALLOW_ALL_TIX is bit value 64 so we OR that with the current attribute value.
              $newAttributes = $currentAttributes ^ 64;
              $principalObj->setAttributes($newAttributes);
              $principalObj->save();
              $msg = "syncPrincipal: Enabled principal $principal";
              $this->log($msg);
            } catch (Exception $e) {
              $msg = "syncPrincipal: Unable to enable principal: ";
              $msg = $msg . print_r($e->getMessage(), true);
              $this->log($msg);
              return false;
            }
          }
      }
    }

    return true;
  }
}
