<?php

/**
 * @file
 * Creates the Selectamix content model: tune, mix_connection, mix_route.
 *
 * Run with: ddev drush php:script scripts/create-content-model.php
 * Idempotent: existing types and fields are left alone.
 */

use Drupal\field\Entity\FieldConfig;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\node\Entity\NodeType;

const AUDIO_EXTENSIONS = 'mp3 wav flac ogg m4a aac';

function sm_type(string $id, string $label, string $description): void {
  if (NodeType::load($id)) {
    return;
  }
  NodeType::create([
    'type' => $id,
    'name' => $label,
    'description' => $description,
    'new_revision' => TRUE,
    'display_submitted' => FALSE,
  ])->save();
  echo "Created node type $id\n";
}

/**
 * Creates storage, field instance, form and view display for one field.
 */
function sm_field(string $bundle, string $name, string $type, string $label, array $opts = []): void {
  if (!FieldStorageConfig::loadByName('node', $name)) {
    FieldStorageConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'type' => $type,
      'cardinality' => $opts['cardinality'] ?? 1,
      'settings' => $opts['storage'] ?? [],
    ])->save();
  }
  if (!FieldConfig::loadByName('node', $bundle, $name)) {
    FieldConfig::create([
      'field_name' => $name,
      'entity_type' => 'node',
      'bundle' => $bundle,
      'label' => $label,
      'description' => $opts['description'] ?? '',
      'required' => $opts['required'] ?? FALSE,
      'settings' => $opts['field'] ?? [],
      'default_value' => $opts['default'] ?? [],
    ])->save();
    echo "Created field $bundle.$name\n";
  }
  $repo = \Drupal::service('entity_display.repository');
  $repo->getFormDisplay('node', $bundle)
    ->setComponent($name, $opts['widget'] ?? [])
    ->save();
  $repo->getViewDisplay('node', $bundle)
    ->setComponent($name, ($opts['formatter'] ?? []) + ['label' => 'inline'])
    ->save();
}

function sm_file_opts(string $directory, string $description = ''): array {
  return [
    'storage' => [
      'uri_scheme' => 'public',
      'target_type' => 'file',
      'display_field' => FALSE,
      'display_default' => FALSE,
    ],
    'field' => [
      'handler' => 'default:file',
      'file_extensions' => AUDIO_EXTENSIONS,
      'file_directory' => $directory,
      'max_filesize' => '',
      'description_field' => FALSE,
    ],
    'description' => $description,
    'widget' => ['type' => 'file_generic'],
    'formatter' => ['type' => 'file_audio', 'settings' => ['controls' => TRUE, 'autoplay' => FALSE, 'loop' => FALSE, 'multiple_file_display_type' => 'tags']],
  ];
}

function sm_ref_opts(string $target_bundle, string $description = '', int $cardinality = 1, bool $required = TRUE): array {
  return [
    'storage' => ['target_type' => 'node'],
    'field' => [
      'handler' => 'default:node',
      'handler_settings' => ['target_bundles' => [$target_bundle => $target_bundle], 'auto_create' => FALSE],
    ],
    'description' => $description,
    'required' => $required,
    'cardinality' => $cardinality,
    'widget' => ['type' => 'entity_reference_autocomplete'],
    'formatter' => ['type' => 'entity_reference_label', 'settings' => ['link' => TRUE]],
  ];
}

function sm_decimal_opts(int $precision, int $scale, string $description = ''): array {
  return [
    'storage' => ['precision' => $precision, 'scale' => $scale],
    'description' => $description,
    'widget' => ['type' => 'number'],
    'formatter' => ['type' => 'number_decimal', 'settings' => ['scale' => $scale, 'decimal_separator' => '.', 'thousand_separator' => '', 'prefix_suffix' => TRUE]],
  ];
}

function sm_list_opts(array $values, string $default, string $description = ''): array {
  // In code the storage expects the flat value => label form; it is converted
  // to the value/label sequence on save.
  return [
    'storage' => ['allowed_values' => $values, 'allowed_values_function' => ''],
    'default' => [['value' => $default]],
    'description' => $description,
    'widget' => ['type' => 'options_select'],
    'formatter' => ['type' => 'list_default'],
  ];
}

// --- Tune -------------------------------------------------------------------
sm_type('tune', 'Tune', 'A tune (a specific recording) that can be mixed into and out of.');
sm_field('tune', 'field_artist', 'string', 'Artist', ['required' => TRUE, 'widget' => ['type' => 'string_textfield'], 'formatter' => ['type' => 'string']]);
sm_field('tune', 'field_version', 'string', 'Version', ['description' => 'Radio edit, extended mix, remix name. A different version is a different tune.', 'widget' => ['type' => 'string_textfield'], 'formatter' => ['type' => 'string']]);
sm_field('tune', 'field_bpm', 'decimal', 'BPM', sm_decimal_opts(6, 2, 'Tempo in beats per minute.'));
sm_field('tune', 'field_key', 'string', 'Key', ['description' => 'Musical key, e.g. Camelot 8A or F minor.', 'widget' => ['type' => 'string_textfield'], 'formatter' => ['type' => 'string']]);
sm_field('tune', 'field_duration', 'decimal', 'Duration (seconds)', sm_decimal_opts(10, 3));
sm_field('tune', 'field_first_downbeat', 'decimal', 'First downbeat (seconds)', sm_decimal_opts(10, 3, 'Offset of beat 1 of bar 1. With BPM this makes every bar position computable.'));
sm_field('tune', 'field_isrc', 'string', 'ISRC', ['description' => 'International Standard Recording Code. Identifies this exact recording across music services.', 'widget' => ['type' => 'string_textfield'], 'formatter' => ['type' => 'string']]);
sm_field('tune', 'field_musicbrainz_id', 'string', 'MusicBrainz recording ID', ['widget' => ['type' => 'string_textfield'], 'formatter' => ['type' => 'string']]);
sm_field('tune', 'field_audio', 'file', 'Audio file', sm_file_opts('selectamix/tunes', 'Full-length audio used by the player.'));
sm_field('tune', 'field_preview_clip', 'file', 'Preview clip', sm_file_opts('selectamix/previews', 'Short excerpt played from the network explorer.'));

// --- Mix connection ---------------------------------------------------------
sm_type('mix_connection', 'Mix connection', 'A directed, human-curated mix from an outgoing tune into an incoming tune.');
sm_field('mix_connection', 'field_from_tune', 'entity_reference', 'Outgoing tune', sm_ref_opts('tune', 'The tune being mixed out of.'));
sm_field('mix_connection', 'field_to_tune', 'entity_reference', 'Incoming tune', sm_ref_opts('tune', 'The tune being mixed into.'));
sm_field('mix_connection', 'field_out_cue_bar', 'integer', 'Out cue (bar)', ['description' => 'Bar of the outgoing tune at which the blend starts.', 'widget' => ['type' => 'number'], 'formatter' => ['type' => 'number_integer']]);
sm_field('mix_connection', 'field_in_cue_bar', 'integer', 'In cue (bar)', ['description' => 'Bar of the incoming tune that lines up with the out cue.', 'widget' => ['type' => 'number'], 'formatter' => ['type' => 'number_integer']]);
sm_field('mix_connection', 'field_blend_bars', 'integer', 'Blend length (bars)', ['default' => [['value' => 8]], 'widget' => ['type' => 'number'], 'formatter' => ['type' => 'number_integer']]);
sm_field('mix_connection', 'field_tempo_master', 'list_string', 'Tempo master', sm_list_opts(['from' => 'Outgoing tune keeps its tempo', 'to' => 'Incoming tune keeps its tempo', 'midpoint' => 'Meet in the middle'], 'from', 'Which tune plays at its native speed. The other is varispeeded to match.'));
sm_field('mix_connection', 'field_fader_curve', 'list_string', 'Fader curve', sm_list_opts(['equal_power' => 'Equal power', 'linear' => 'Linear', 'cut' => 'Cut'], 'equal_power'));
sm_field('mix_connection', 'field_mix_clip', 'file', 'Mix clip', sm_file_opts('selectamix/mixclips', 'Optional pre-rendered excerpt of the blend.'));
sm_field('mix_connection', 'field_notes', 'text_long', 'Notes', ['widget' => ['type' => 'text_textarea'], 'formatter' => ['type' => 'text_default']]);

// --- Mix route --------------------------------------------------------------
sm_type('mix_route', 'Mix route', 'An ordered chain of mix connections: a shareable, playable mix.');
sm_field('mix_route', 'field_connections', 'entity_reference', 'Connections', sm_ref_opts('mix_connection', 'In playing order. Each connection must start where the previous one ended.', -1));
sm_field('mix_route', 'field_notes', 'text_long', 'Notes', ['widget' => ['type' => 'text_textarea'], 'formatter' => ['type' => 'text_default']]);

echo "Content model ready.\n";
