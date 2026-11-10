<?php

namespace App\Demo;

/**
 * The invented wording DemoForm writes into its forms: names, places, free-text answers
 * and areas of improvement. Every line here is made up and generic; none is taken from a
 * real movement's form.
 */
final class DemoFormText
{
    /** Invented first names and surnames, combined at random: no real staff member. */
    public const FIRST_NAMES = [
        'Amara', 'Kofi', 'Thandiwe', 'Chipo', 'Mercy', 'Tendai', 'Ngozi', 'Kwabena', 'Esi', 'Fatoumata',
        'Joseph', 'Ruth', 'Daniel', 'Abena', 'Lindiwe', 'Moses', 'Grace', 'Samuel', 'Halima', 'Emmanuel',
    ];

    public const SURNAMES = [
        'Mutale', 'Achieng', 'Owusu', 'Ndlovu', 'Diallo', 'Mensah', 'Kamau', 'Tembo', 'Nkosi', 'Okafor',
        'Sesay', 'Abebe', 'Rakoto', 'Kariuki', 'Phiri', 'Nyirenda', 'Asante', 'Lungu', 'Ouedraogo', 'Banda',
    ];

    public const STREETS = ['Independence Avenue', 'Church Road', 'Unity Street', 'Freedom Way', 'Mission Road', 'Lakeside Drive', 'Youth Centre Lane', 'Station Road'];

    /** The capital of each National Movement's country, for an invented address. */
    public const CITIES = [
        'Sierra Leone' => 'Freetown', 'Madagascar' => 'Antananarivo', 'Ethiopia' => 'Addis Ababa', 'Zambia' => 'Lusaka',
        'Togo' => 'Lomé', 'Ghana' => 'Accra', 'Liberia' => 'Monrovia', 'Zimbabwe' => 'Harare', 'South Africa' => 'Pretoria',
        'Cameroon' => 'Yaoundé', 'South Sudan' => 'Juba', 'Nigeria' => 'Lagos', 'Senegal' => 'Dakar', 'Namibia' => 'Windhoek',
        'Burundi' => 'Bujumbura', 'Kenya' => 'Nairobi', 'Benin' => 'Cotonou', 'Niger' => 'Niamey', 'Tanzania' => 'Dodoma',
        'Gambia' => 'Banjul', 'Malawi' => 'Lilongwe', 'Guinea-Bissau' => 'Bissau', 'Guinea' => 'Conakry', 'Uganda' => 'Kampala',
        'Rwanda' => 'Kigali', 'Botswana' => 'Gaborone', 'Lesotho' => 'Maseru', 'Eswatini' => 'Mbabane', 'Mozambique' => 'Maputo',
        'Angola' => 'Luanda', 'Egypt' => 'Cairo', 'Mauritius' => 'Port Louis', 'Sudan' => 'Khartoum',
    ];

    /**
     * Each country's currency: its code (what Interpreter::currencyCode reads at Q208), its
     * name, and roughly how many units make one US dollar, to put figures on a local scale.
     *
     * @var array<string, array{0: string, 1: string, 2: int|float}>
     */
    public const CURRENCIES = [
        'Sierra Leone' => ['SLE', 'Sierra Leonean Leone', 23], 'Madagascar' => ['MGA', 'Malagasy Ariary', 4500],
        'Ethiopia' => ['ETB', 'Ethiopian Birr', 130], 'Zambia' => ['ZMW', 'Zambian Kwacha', 25],
        'Togo' => ['XOF', 'West African CFA franc', 600], 'Ghana' => ['GHS', 'Ghanaian Cedi', 15],
        'Liberia' => ['USD', 'US Dollar', 1], 'Zimbabwe' => ['USD', 'US Dollar', 1],
        'South Africa' => ['ZAR', 'South African Rand', 18], 'Cameroon' => ['XAF', 'Central African CFA franc', 600],
        'South Sudan' => ['USD', 'US Dollar', 1], 'Nigeria' => ['NGN', 'Nigerian Naira', 1500],
        'Senegal' => ['XOF', 'West African CFA franc', 600], 'Namibia' => ['NAD', 'Namibian Dollar', 18],
        'Burundi' => ['BIF', 'Burundian Franc', 2900], 'Kenya' => ['KES', 'Kenyan Shilling', 130],
        'Benin' => ['XOF', 'West African CFA franc', 600], 'Niger' => ['XOF', 'West African CFA franc', 600],
        'Tanzania' => ['TZS', 'Tanzanian Shilling', 2600], 'Gambia' => ['GMD', 'Gambian Dalasi', 70],
        'Malawi' => ['MWK', 'Malawian Kwacha', 1750], 'Guinea-Bissau' => ['XOF', 'West African CFA franc', 600],
        'Guinea' => ['GNF', 'Guinean Franc', 8600], 'Uganda' => ['UGX', 'Ugandan Shilling', 3700],
        'Rwanda' => ['RWF', 'Rwandan Franc', 1400], 'Botswana' => ['BWP', 'Botswana Pula', 13.5],
        'Lesotho' => ['LSL', 'Lesotho Loti', 18], 'Eswatini' => ['SZL', 'Swazi Lilangeni', 18],
        'Mozambique' => ['MZN', 'Mozambican Metical', 64], 'Angola' => ['AOA', 'Angolan Kwanza', 900],
        'Egypt' => ['EGP', 'Egyptian Pound', 49], 'Mauritius' => ['MUR', 'Mauritian Rupee', 46],
    ];

    public const REGISTRATION = [
        'Non-profit association registered under the Societies Act',
        'Non-governmental organisation registered with the national NGO board',
        'Faith-based association registered with the Ministry of Home Affairs',
        'Company limited by guarantee, not for profit',
    ];

    public const ELECTED_BY = [
        'The General Assembly, every three years',
        'The National Council of member YMCAs at the Annual General Meeting',
        'The Annual General Meeting of members',
    ];

    public const SKILLS_APPROACH = [
        'A skills matrix is reviewed by the Nominations Committee before each election',
        'Candidates are sought for finance, legal, fundraising and youth-work skills',
        'An open call for candidates against a published skills profile',
    ];

    public const IMPACT_TOOLS = [
        'Annual participant surveys and outcome tracking per programme',
        'Baseline and endline surveys, with Most Significant Change stories',
        'A results framework with outcome indicators for each programme',
    ];

    public const VISIONS = [
        'Vision: young people empowered to lead healthy, purposeful lives. Mission: to equip young people with skills, faith and opportunity to transform their communities.',
        'Vision: a just and caring society where every young person thrives. Mission: to develop the body, mind and spirit of young people through service and leadership.',
        'Vision: resilient young people building peaceful communities. Mission: to nurture young leaders through programmes in education, livelihoods and wellbeing.',
    ];

    public const VALUES = [
        'Integrity, respect, service and inclusion',
        'Faith, accountability, compassion and teamwork',
        'Love, respect, honesty and responsibility',
    ];

    public const BOARD_INCLUSION = [
        'An induction for every new Board member, and committee chairs rotated each year',
        'Board papers shared a week ahead and a mentoring pair for each new member',
    ];

    public const YOUTH_INCLUSION = [
        'A youth council and a volunteer forum advise the Board every quarter',
        'Young people sit on every programme committee, and volunteers are surveyed each year',
    ];

    public const PROPERTIES = ['The national office building', 'A youth hostel', 'Two vehicles', 'A vocational training centre', 'Land for a planned camp site', 'A guest house'];

    public const PROPERTY_RISKS = [
        'A boundary dispute over part of the camp-site land',
        'The title deed of one building has not yet been transferred to the YMCA',
        'Ageing buildings that need major repairs the budget cannot yet cover',
    ];

    public const SOCIAL_SECURITY = [
        'Yes. All permanent staff are enrolled in the national pension scheme and have medical cover.',
        'Yes, statutory social security for permanent staff; project staff are covered under their contracts.',
        'Partly: permanent staff only. Medical cover is being negotiated.',
    ];

    public const LICENSING = [
        'Yes, each member YMCA signs a brand-use agreement',
        'No, brand use is guided by the constitution only',
    ];

    /** What a movement would write as an area of improvement when a criterion is not met. */
    public const IMPROVE = [
        'Q201' => 'Prepare and approve an annual budget before the financial year starts.',
        'Q202' => 'Report actual results against the budget every quarter.',
        'Q203' => 'Put the minimum internal financial controls in place and document them.',
        'Q204' => 'Arrange project audits for every grant, as funders expect.',
        'Q205' => 'Commission an independent annual audit of the accounts.',
        'Q206' => 'Agree a multi-year engagement with an independent auditor, rotated every few years.',
        'Q207' => 'Publish the audited financial report on the website.',
        'income1' => 'Reduce reliance on a single income source.',
        'income2' => 'Diversify income so that no source is more than half of the total.',
        'Q223' => 'Grow local income to reduce dependence on international funding.',
        'Q225' => 'Raise more unrestricted funding for core costs.',
        'Q227' => 'Reduce the exposure to any single major funder.',
        'Q228' => 'Prepare a risk mitigation plan in case a major funder withdraws.',
        'Q238' => 'Secure funding to cover the next two years of core costs.',
        'Q239' => 'Start building a cash reserve for emergencies.',
        'Q241' => 'Grow the reserve to cover at least six months of costs.',
        'Q242' => 'Agree a plan to pay down outstanding debt.',
        'Q244' => 'Reduce the interest and repayment risks on existing loans.',
        'Q320' => 'Recruit Board members against the skills the strategy needs.',
        'Q322' => 'Shorten Board terms to fewer than five years.',
        'Q323' => 'Introduce term limits for Board members.',
        'Q325' => 'Introduce a term limit for the President.',
        'Q325#2' => 'Hold an annual Board self-evaluation.',
        'Q326#2' => 'Hold at least two Board meetings a year, with minutes.',
        'Q327' => 'Evaluate the General Secretary’s performance every year.',
        'Q328' => 'Separate the roles of General Secretary and Chair.',
        'Q330' => 'Adopt a temporary succession plan for emergencies.',
        'Q331' => 'Adopt a long-term succession plan for the General Secretary.',
        'Q332' => 'Insure key properties and assets.',
        'Q333' => 'Take out liability cover for the Board and management.',
        'Q410' => 'Update the constitution to meet the latest national requirements.',
        'Q412' => 'Adopt a conflict of interest policy.',
        'Q413' => 'Adopt a safeguarding policy and train staff and volunteers on it.',
        'Q414' => 'Set up a whistleblowing mechanism.',
        'Q415' => 'Adopt a diversity, equity and inclusion policy.',
        'Q416' => 'Adopt a health and safety policy.',
        'Q417' => 'Approve a human resources policy.',
        'Q418' => 'Write a financial procedures manual.',
        'Q419' => 'Adopt a gift acceptance policy.',
        'Q420' => 'Adopt a cybersecurity and AI policy.',
        'Q421' => 'Adopt a data protection and privacy policy.',
        'Q422' => 'Document a procurement procedure.',
        'Q423' => 'Write down the Board nomination and election procedure.',
        'Q424' => 'Set clear membership criteria.',
        'Q425' => 'Adopt a code of conduct for Board members.',
        'Q426' => 'Adopt a code of conduct for staff and volunteers.',
        'Q427' => 'Introduce wellbeing and professional development policies for staff and volunteers.',
        'Q428' => 'Develop a climate sustainability policy.',
        'Q429' => 'Adopt an anti-corruption policy.',
        'Q430' => 'Develop a risk management plan.',
        'Q511' => 'Monitor every project against its plan.',
        'Q512' => 'Set up organisation-wide monitoring across all programmes.',
        'Q513' => 'Evaluate projects at their end.',
        'Q514' => 'Introduce an organisation-wide evaluation process.',
        'Q515' => 'Record lessons learnt and share them across branches.',
        'Q516' => 'Introduce simple tools to measure impact.',
        'Q610' => 'Develop a strategic plan for the next five years.',
        'Q612' => 'Involve staff in developing the strategic plan.',
        'Q613' => 'Involve the Board in developing the strategic plan.',
        'Q614' => 'Consult members when the strategic plan is developed.',
        'Q615' => 'Involve volunteers in developing the strategic plan.',
        'Q616' => 'Consult young people and communities on the strategic plan.',
        'Q618' => 'Review the strategic plan every year.',
        'Q619' => 'Add an innovation component to the strategic plan.',
        'Q620' => 'Reflect Vision 2030 in the strategic plan.',
        'Q630' => 'Check that the mission and values align with the YMCA’s Christian values.',
        'Q711' => 'Increase the number of young people on the Board.',
        'Q712' => 'Work towards gender balance on the Board.',
        'Q714' => 'Introduce a procedure that ensures every Board member can take part equally.',
        'Q716' => 'Adopt policies for the inclusion of young people, volunteers and staff.',
        'Q810' => 'Publish an annual report every year.',
        'Q811' => 'Keep the website up to date.',
        'Q812' => 'Develop a communications strategy.',
        'Q813' => 'Name a staff member or volunteer responsible for communications.',
        'Q814' => 'Register the YMCA name and logo.',
        'Q1001' => 'Recruit more volunteers to deliver the operational plan.',
        'Q1002' => 'Recruit staff for the gaps in the operational plan.',
        'Q1003' => 'Set up a regular training programme for staff and volunteers.',
        'Q1004' => 'Put every employee on a formal contract.',
        'Q1007' => 'Write a job description for every position.',
    ];

    /** The area of improvement for a category whose scored criteria are all met. */
    public const SUSTAIN = [
        'financial' => 'Keep building the reserve and widen the base of local income.',
        'governance' => 'Keep the Board’s annual self-evaluation and renew its skills through succession planning.',
        'constitution' => 'Review all policies every three years and train new staff on them.',
        'me' => 'Use monitoring data more in Board decisions and in reports to partners.',
        'strategy' => 'Keep the yearly review of the strategic plan and share progress with members.',
        'diversity' => 'Keep youth and gender representation under review at every election.',
        'comms' => 'Measure the reach of communications and adjust the strategy each year.',
        'property' => 'Keep the property register and maintenance plan up to date.',
        'staff' => 'Keep investing in staff and volunteer development.',
    ];
}
