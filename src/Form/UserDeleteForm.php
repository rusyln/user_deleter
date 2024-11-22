<?php

namespace Drupal\user_deleter\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\user\Entity\User;

/**
 * Form to upload a CSV file and delete users based on usernames.
 */
class UserDeleteForm extends FormBase {

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'user_deleter_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    // File upload field
    $form['file'] = [
      '#type' => 'file',
      '#title' => $this->t('Upload CSV file'),
      '#description' => $this->t('Upload a CSV file containing usernames to delete. Each username should be on a new line.'),
      '#required' => TRUE,
    ];
  
    // Actions section (Upload & Validate button)
    $form['actions']['#type'] = 'actions';
    $form['actions']['upload'] = [
      '#type' => 'submit',
      '#value' => $this->t('Upload & Validate'),
      '#submit' => ['::validateCsvFile'],
    ];
  
    // If the form state contains validated usernames, show the "Delete Users" button
    $validated_usernames = $form_state->get('validated_usernames');
    
    // Show the "Delete Users" button and the list of validated usernames if available
    if (!empty($validated_usernames)) {
      // Show "Delete Users" button
      $form['actions']['delete'] = [
        '#type' => 'submit',
        '#value' => $this->t('Delete Users'),
        '#submit' => ['::deleteUsers'],
      ];
  
      // Display validated usernames
      $form['validated_usernames'] = [
        '#type' => 'details',
        '#title' => $this->t('Validated Usernames'),
        '#open' => TRUE,
        '#markup' => $this->t('The following usernames were validated: %usernames', [
          '%usernames' => implode(', ', $validated_usernames),
        ]),
      ];
    }
  
    return $form;
  }
  
  public function validateCsvFile(array &$form, FormStateInterface $form_state) {
    // Validate file extension and save the file.
    $validators = ['file_validate_extensions' => ['csv']];
    $file = file_save_upload('file', $validators, FALSE, 0);
  
    if ($file) {
      // Save file in the 'temporary://' directory with a unique name.
      $file_system = \Drupal::service('file_system');
      $unique_filename = 'users-to-delete_' . time() . '.csv'; // Unique filename using timestamp.
      $destination = $file_system->getTempDirectory() . '/' . $unique_filename;
  
      try {
        // Copy the file to the destination with the unique name
        $file_system->copy($file->getFileUri(), $destination, \Drupal\Core\File\FileSystemInterface::EXISTS_REPLACE);
  
        $usernames = [];
        if (($handle = fopen($destination, 'r')) !== FALSE) {
          while (($data = fgetcsv($handle, 1000, ',')) !== FALSE) {
            $username = trim($data[0]);
            if (!empty($username)) {
              $usernames[] = $username;
            }
          }
          fclose($handle);
        }
  
        if (!empty($usernames)) {
          // Save validated usernames to the form state
          $form_state->set('validated_usernames', $usernames); 
          $this->messenger()->addMessage($this->t('CSV file validated successfully. Found %count usernames.', ['%count' => count($usernames)]));
        } else {
          $this->messenger()->addWarning($this->t('No valid usernames found in the CSV file.'));
        }
      } catch (\Exception $e) {
        $this->messenger()->addError($this->t('An error occurred while processing the file: %message', ['%message' => $e->getMessage()]));
      }
    } else {
      $this->messenger()->addError($this->t('Please upload a valid CSV file.'));
    }
  
    // Rebuild the form to show the Delete button
    $form_state->setRebuild(TRUE);
  }
  
  public function deleteUsers(array &$form, FormStateInterface $form_state) {
    // Get validated usernames from form state
    $validated_usernames = $form_state->get('validated_usernames');
    
    // If no validated usernames, show the warning
    if (empty($validated_usernames)) {
      $this->messenger()->addError($this->t('Please upload a valid CSV file.'));
      return;
    }
  
    // Proceed with deleting users
    $deleted_users = 0;
    $not_found_users = [];
  
    foreach ($validated_usernames as $username) {
      $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $username]);
      if ($users) {
        foreach ($users as $user) {
          $user->delete();
          $deleted_users++;
        }
      } else {
        $not_found_users[] = $username;
      }
    }
  
    $this->messenger()->addMessage($this->t('Deleted %count users.', ['%count' => $deleted_users]));
    if (!empty($not_found_users)) {
      $this->messenger()->addWarning($this->t('The following usernames were not found: %usernames', ['%usernames' => implode(', ', $not_found_users)]));
    }
  }
  
/**
 * {@inheritdoc}
 */
public function submitForm(array &$form, FormStateInterface $form_state) {
    $usernames = $form_state->get('validated_usernames');
    
    if (empty($usernames)) {
      $this->messenger()->addError($this->t('No valid usernames found to delete.'));
      return;
    }
  
    $deleted_users = 0;
    $not_found_users = [];
  
    // Iterate over the usernames and delete the users
    foreach ($usernames as $username) {
      // Load user by username
      $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['name' => $username]);
  
      if ($users) {
        foreach ($users as $user) {
          // Check if the user exists and delete
          if ($user->isActive()) {
            $user->delete();
            $deleted_users++;
          }
        }
      } else {
        $not_found_users[] = $username;
      }
    }
  
    // Provide feedback to the admin about the results
    $this->messenger()->addMessage($this->t('Deleted %count users.', ['%count' => $deleted_users]));
  
    // If some users were not found, show a warning message
    if (!empty($not_found_users)) {
      $this->messenger()->addWarning($this->t('The following usernames were not found: %usernames', ['%usernames' => implode(', ', $not_found_users)]));
    }
  }
  
}