<?php

/**
 * @file classes/migration/upgrade/v3_4_0/I7014_DoiMigration.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class I7014_DoiMigration
 * @brief Describe upgrade/downgrade operations for DB table dois.
 */

namespace PKP\migration\upgrade\v3_4_0;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class I7014_DoiMigration extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
        // DOIs
        Schema::create('dois', function (Blueprint $table) {
            $table->bigInteger('doi_id')->autoIncrement();
            $table->bigInteger('context_id');
            $table->string('doi');
            $table->smallInteger('status')->default(1);

            // TODO: Needs to be OJS specific
            $table->foreign('context_id')->references('journal_id')->on('journals');
        });

        // Settings
        Schema::create('doi_settings', function (Blueprint $table) {
            $table->bigInteger('doi_id');
            $table->string('locale', 14)->default('');
            $table->string('setting_name', 255);
            $table->text('setting_value')->nullable();

            $table->index(['doi_id'], 'doi_settings_doi_id');
            $table->unique(['doi_id', 'locale', 'setting_name'], 'doi_settings_pkey');
        });
    }

    /**
     * Reverse the downgrades
     */
    public function down()
    {
        Schema::drop('dois');
        Schema::drop('doi_settings');
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\migration\upgrade\v3_4_0\I7014_DoiMigration', '\I7014_DoiMigration');
}
