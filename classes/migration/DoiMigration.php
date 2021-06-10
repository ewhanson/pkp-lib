<?php

/**
 * @file classes/migration/DoiMigration.inc.php
 *
 * Copyright (c) 2014-2021 Simon Fraser University
 * Copyright (c) 2000-2021 John Willinsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 *
 * @class DoiMigration
 * @brief Describe upgrade/downgrade operations for DB table dois.
 */

namespace PKP\migration;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class DoiMigration extends Migration
{
    /**
     * Run the migrations.
     */
    public function up()
    {
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

            $table->foreign('doi_id')->references('doi_id')->on('dois');
        });
    }

    /**
     * Reverse the downgrades
     */
    public function down()
    {
    }
}

if (!PKP_STRICT_MODE) {
    class_alias('\PKP\migration\DoiMigration', '\DoiMigration');
}
