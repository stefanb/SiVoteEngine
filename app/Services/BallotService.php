<?php

namespace App\Services;

use App\BallotComponents\ApprovalVote\v1\ApprovalVote;
use App\BallotComponents\BallotComponentType;
use App\BallotComponents\FirstPastThePost\v1\FirstPastThePost;
use App\BallotComponents\RankedChoice\v1\RankedChoice;
use App\BallotComponents\YesNo\v1\YesNo;
use App\Models\Ballot;
use App\Models\BallotComponent;
use App\Models\Vote;
use League\Csv\Writer;

class BallotService
{
    protected $components = [
        'YesNo' => [
            'v1' => YesNo::class
        ],
        'FirstPastThePost' => [
            'v1' => FirstPastThePost::class
        ],
        'RankedChoice' => [
            'v1' => RankedChoice::class
        ],
        'ApprovalVote' => [
            'v1' => ApprovalVote::class
        ]
    ];

    public function getComponentTree()
    {
        $tree = [];
        foreach ($this->components as $type => $versions) {
            $tree[$type] = [];
            foreach ($versions as $version => $class) {
                $tree[$type][$version] = [
                    'needsOptions' => $class::$needsOptions,
                    'livewireForm' => $class::$livewireForm,
                    'optionsValidators' => $class::$optionsValidator,
                    'strings' => $class::strings()
                ];
            }
        }
        return $tree;
    }

    public function getBallotTypes()
    {
        return array_keys($this->components);
    }

    public function getBallotVersions($ballotType)
    {
        if (!array_key_exists($ballotType, $this->components)) {
            return [];
        }
        return array_keys($this->components[$ballotType]);
    }

    public function getSubmissionValidators(Ballot $ballot)
    {
        return array_reduce($ballot->components, function ($validators, $component) use ($ballot) {
            return array_merge($validators, $this->components[$component['type']][$component['version']]::getSubmissionValidator($component, $ballot->election));
        }, []);
    }

    public function getPartialSubmissionValidators(Ballot $ballot, $params)
    {
        return array_reduce($ballot->components, function ($validators, $component) use ($ballot, $params) {
            if (!array_key_exists($component->id, $params)) {
                return $validators;
            }
            return array_merge($validators, $this->components[$component['type']][$component['version']]::getSubmissionValidator($component, $ballot->election));
        }, []);
    }

    public function getComponentValidators(BallotComponent $component)
    {
        return $this->components[$component->type][$component->version]::getSubmissionValidator($component, $component->ballot->election);
    }

    public function getBallotComponentClass($ballotType, $version)
    {
        return $this->components[$ballotType][$version];
    }

    /**
     * This returns an instance, but most classes are completely static, so it's currently unused.
     */
    public function getBallotComponentClassInstance($ballotType, $version, $args): BallotComponentType
    {
        $class = $this->getBallotComponentClass($ballotType, $version);
        return new $class($args);
    }

    public function calculateResults(Ballot $ballot)
    {
        $votes = $ballot->cast_votes;
        return $ballot->components()->get()->reduce(function ($acc, $component) use ($votes) {
            $componentClass = $this->getBallotComponentClassInstance($component['type'], $component['version'], $component['settings']);
            $acc[$component->id] = [
                'results' => $componentClass::calculateResults($votes, $component),
                'title' => $component->title,
                'description' => $component->description,
                'type' => $component->type
            ];
            return $acc;
        }, []);
    }

    public function resultsCsv(Ballot $ballot)
    {
        $votes = $ballot->castVotes();
        $components = $ballot->components()->get();

        $header = $components->pluck('title')->prepend(__('ballot.voteId'))->toArray();

        $results_per_component = $components->map(function ($component) use ($votes) {
            $componentClass = $this->getBallotComponentClassInstance($component['type'], $component['version'], $component['settings']);
            return $votes->map(function (Vote $vote) use ($componentClass, $component) {
                return $componentClass::valuesToCsv($vote->values, $component->id);
            });
        });

        $final_values = $votes->pluck('id')->zip(...$results_per_component);

        $csv = Writer::createFromString();

        $csv->insertOne($header);
        $csv->insertAll($final_values->toArray());

        return $csv->getContent();
    }
}
