<?php

$base = [
    'profile' => [
        'name' => 'Your Name',
        'location' => 'Your Location',
        'work_authorisation' => 'Describe where you are based and any work authorisation constraints.',
    ],

    'summary' => 'Senior backend / platform engineer focused on APIs, systems integration, and delivery of reliable software in multi-team environments.',

    'core_skills' => [
        'Technical leadership',
        'Backend development',
        'API design',
        'Systems integration',
        'Architecture and technical discovery',
        'SQL and data modelling',
    ],

    'secondary_skills' => [
        'Python scripting',
        'Frontend collaboration',
        'Cloud infrastructure',
        'Automation and tooling',
    ],

    'target_roles' => [
        'Tech Lead',
        'Senior Backend Engineer',
        'Platform Engineer',
    ],

    'preferences' => [
        'scope' => [
            'Backend',
            'APIs',
            'Platform engineering',
            'Systems integration',
        ],
        'environment' => [
            'Structured engineering culture',
            'Clear technical ownership',
            'Cross-team collaboration',
        ],
        'avoid' => [
            'Pure frontend roles',
            'Roles with unclear scope',
            'Roles that require experience I do not have',
        ],
    ],

    'strengths' => [
        'Turning ambiguous requirements into structured, deliverable solutions',
        'Working across multiple teams and systems',
        'Balancing hands-on engineering with technical leadership',
        'Aligning product, architecture, and implementation',
    ],

    'achievements' => [
        'Replace these with concrete achievements from your own background',
        'Keep them specific and factual',
        'Use outcomes that are easy to defend in interview',
    ],

    'experience_notes' => [
        'primary_language' => 'Describe your main production language or stack',
        'secondary_languages' => 'Describe adjacent languages or frameworks honestly',
        'positioning' => 'State how you want to be positioned, for example senior IC, tech lead, or manager',
    ],

    'tone' => [
        'style' => 'Professional, natural, concise',
        'guidelines' => [
            'Avoid generic or overly enthusiastic language',
            'Sound like an experienced engineer',
            'Be confident but not exaggerated',
            'Prefer clarity over buzzwords',
        ],
    ],

    'constraints' => [
        'Do not invent experience',
        'Do not overstate seniority',
        'Avoid corporate clichés',
        'Keep length around 250-400 words',
    ],

    'customisation_rules' => [
        'Adapt wording to match the job description',
        'Highlight 2-3 relevant strengths only',
        'Reference the company or product when possible',
        'Keep it concise and specific',
    ],

    'variant_selection' => [
        'default' => 'platform',
    ],

    'variants' => [
        'fullstack' => [
            'label' => 'Fullstack / Product Engineer',
            'summary' => 'Senior Fullstack Engineer with strong backend and frontend delivery experience, focused on scalable product development, maintainable systems, and pragmatic execution.',
            'core_skills' => [
                'Fullstack development',
                'Product-focused engineering',
                'API design',
                'Backend architecture',
                'Frontend integration',
                'SQL',
            ],
            'highlight_strengths' => [
                'End-to-end product delivery',
                'Balancing user experience, product needs, and technical design',
                'Building maintainable SaaS-style systems',
            ],
            'emphasis' => [
                'fullstack',
                'product',
                'delivery',
            ],
            'selection_keywords' => [
                'fullstack' => 3,
                'frontend' => 2,
                'react' => 2,
                'vue' => 2,
                'ui' => 1,
                'product' => 2,
                'saas' => 1,
            ],
        ],
        'platform' => [
            'label' => 'Platform / Integration Tech Lead',
            'summary' => 'Senior Tech Lead specialising in API design, systems integration, and platform engineering. Experienced in structuring and delivering complex multi-system environments with a focus on architecture, clarity, and predictable delivery.',
            'core_skills' => [
                'Systems integration',
                'API design',
                'Backend architecture',
                'Middleware and distributed systems',
                'Technical leadership',
                'Architecture and discovery',
            ],
            'highlight_strengths' => [
                'Structuring complex multi-system environments',
                'Cross-team technical alignment',
                'Improving delivery predictability',
            ],
            'emphasis' => [
                'architecture',
                'integration',
                'platform',
            ],
            'selection_keywords' => [
                'api' => 2,
                'integration' => 3,
                'platform' => 3,
                'architecture' => 2,
                'distributed' => 2,
                'middleware' => 2,
                'developer experience' => 2,
                'dx' => 1,
            ],
        ],
    ],

    'closing' => [
        'examples' => [
            'I would be glad to discuss the role and my background in more detail.',
            'I would welcome the opportunity to discuss how I could contribute to your team.',
        ],
    ],
];

$localPath = __DIR__.'/applicant.local.php';

if (file_exists($localPath)) {
    $local = require $localPath;
    if (is_array($local)) {
        return array_replace_recursive($base, $local);
    }
}

return $base;
