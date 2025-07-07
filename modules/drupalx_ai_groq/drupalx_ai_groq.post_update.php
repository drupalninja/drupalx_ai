<?php

/**
 * @file
 * Post update functions for drupalx_ai_groq module.
 */

/**
 * Verify and fix Groq configuration after module installation.
 */
function drupalx_ai_groq_post_update_verify_configuration() {
  $config_factory = \Drupal::configFactory();
  
  // Verify Groq provider settings
  $groq_config = $config_factory->get('ai_provider_groq.settings');
  $status_messages = [];
  
  if ($groq_config->get('api_key') === 'groq_api_key') {
    $status_messages[] = '✅ Groq API key reference configured correctly';
  } else {
    $status_messages[] = '❌ Groq API key reference not set correctly';
  }
  
  if ($groq_config->get('max_tokens') === 4000) {
    $status_messages[] = '✅ Max tokens set to 4000';
  } else {
    $status_messages[] = '❌ Max tokens not set to 4000';
  }
  
  if ($groq_config->get('temperature') === 0.7) {
    $status_messages[] = '✅ Temperature optimized to 0.7';
  } else {
    $status_messages[] = '❌ Temperature not optimized';
  }
  
  // Verify AI module defaults
  $ai_config = $config_factory->get('ai.settings');
  if ($ai_config->get('default_provider') === 'groq') {
    $status_messages[] = '✅ Groq set as default AI provider';
  } else {
    $status_messages[] = '❌ Groq not set as default provider';
  }
  
  // Verify DrupalX AI settings
  $drupalx_config = $config_factory->get('drupalx_ai.settings');
  if ($drupalx_config->get('ai_provider_model') === 'groq:default') {
    $status_messages[] = '✅ DrupalX AI configured to use Groq';
  } else {
    $status_messages[] = '❌ DrupalX AI not configured for Groq';
  }
  
  // Verify API key entity exists
  $key = \Drupal\key\Entity\Key::load('groq_api_key');
  if ($key) {
    $status_messages[] = '✅ Groq API key entity created';
    if ($key->getKeyValue()) {
      $status_messages[] = '✅ Groq API key has a value';
    } else {
      $status_messages[] = '⚠️ Groq API key exists but is empty - add your API key';
    }
  } else {
    $status_messages[] = '❌ Groq API key entity not found';
  }
  
  $final_message = implode("\n", $status_messages);
  \Drupal::logger('drupalx_ai_groq')->info("Configuration verification:\n@status", ['@status' => $final_message]);
  
  return 'DrupalX AI Groq configuration verification completed.';
}