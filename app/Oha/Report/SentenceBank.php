<?php

namespace App\Oha\Report;

/**
 * The wording the platform drafts a report with (DraftReport). For each scored OHA
 * question: what it says when the point is earned ("in place"), what it says when it is
 * not ("gap"), and the opportunity for growth it suggests. Phrases in place and gap are
 * written to follow "has" or "there is", in lower case; opportunities are sentences.
 * {placeholders} take the movement's own figure. Drafted 9 Nov 2026 for review by the
 * Movement Strengthening team: the wording is changed here, nowhere else.
 */
final class SentenceBank
{
    /** Per category: the headline of a key strength, and of a key risk. */
    public const HEADLINES = [
        'financial' => ['strength' => 'Sound financial management', 'risk' => 'Long-term financial sustainability'],
        'governance' => ['strength' => 'Effective governance', 'risk' => 'Governance and leadership continuity'],
        'constitution' => ['strength' => 'A solid policy framework', 'risk' => 'Policy and compliance gaps'],
        'me' => ['strength' => 'Learning from results', 'risk' => 'Limited monitoring and evaluation'],
        'strategy' => ['strength' => 'Clear strategic direction', 'risk' => 'Unclear strategic direction'],
        'diversity' => ['strength' => 'Inclusive, youth-led leadership', 'risk' => 'Youth participation and inclusion'],
        'comms' => ['strength' => 'Visibility and public accountability', 'risk' => 'Low visibility and public accountability'],
        'property' => ['strength' => 'Well-managed property', 'risk' => 'Property risks'],
        'staff' => ['strength' => 'Capable staff and volunteers', 'risk' => 'Human resource capacity'],
    ];

    /** What each health band says about a National Movement, after "This reflects". */
    public const BAND_MEANING = [
        'excellent' => 'a strong, well-run National Movement that can serve as a model of good practice for others',
        'strong' => 'a healthy National Movement with solid foundations to build on',
        'developing' => 'a National Movement with good foundations and clear room to grow',
        'atrisk' => 'a National Movement with important gaps that call for focused support',
        'critical' => 'a National Movement whose foundations need urgent attention and close support',
    ];

    /**
     * Question code => [in place, gap, opportunity].
     *
     * @var array<string, array{0: string, 1: string, 2: string}>
     */
    public const ITEMS = [
        // Financial Stability
        'Q201' => ['an annual financial plan or budget', 'no annual financial plan or budget', 'Adopt an annual budget approved by the Board, and use it to guide spending.'],
        'Q202' => ['regular reporting of actual results against the budget', 'no reporting of actual results against the budget', 'Report actual income and spending against the budget to the Board at least every quarter.'],
        'Q203' => ['the internal financial controls the law and its own policies require', 'gaps in the minimum internal financial controls', 'Put in place the minimum internal controls the law and YMCA policies require, such as two signatories and a separation of duties.'],
        'Q204' => ['funder audits of the grants it receives', 'no funder audits of the grants it receives', 'Agree a project audit with the funder for each grant, to show the money was spent as agreed.'],
        'Q205' => ['a comprehensive annual audit by an independent auditor', 'no independent annual audit', 'Commission a comprehensive annual audit by an independent auditor, and present it to the Board.'],
        'Q206' => ['an independent auditor appointed for between one and five years', 'an auditor appointment outside the recommended one to five years', 'Review the auditor appointment so the same auditor serves between one and five years, keeping the audit independent.'],
        'Q207' => ['a published annual financial report', 'no public annual financial report', 'Publish the annual financial report, on the website or in the annual report.'],
        'Q214' => ['a diverse income base, with no single source above half of its income', 'a reliance on one source for {top}% of its income', 'Diversify income so that no single source provides more than half, for example through local fundraising and social enterprise.'],
        'Q223' => ['a funding base that is mostly local or national', 'a reliance on international sources for {international}% of its income', 'Grow local and national income to reduce dependence on international funding.'],
        'Q225' => ['enough unrestricted funding to cover core costs', '{restricted}% of its funding restricted to specific activities', 'Increase unrestricted income, such as membership fees and local giving, to fund core costs.'],
        'Q227' => ['no dependence on any single major funder', 'a heavy dependence on one major funder or partner', 'Reduce dependence on any single funder by broadening the base of donors and partners.'],
        'Q228' => ['a backup plan should a major funder withdraw', 'no backup plan should a major funder withdraw', 'Prepare a risk-mitigation plan for the loss of a major funder.'],
        'Q238' => ['the means to cover its expenses for the next two years', 'uncertainty about covering its expenses over the next two years', 'Build a two-year financial forecast and a plan to close any funding gap it shows.'],
        'Q239' => ['a cash reserve for financial challenges', 'no cash reserve to absorb financial challenges', 'Build a cash reserve, aiming for at least six months of operating costs.'],
        'Q241' => ['a reserve lasting {months} months', 'a reserve lasting only {months} months', 'Grow the reserve to cover more than six months of operating costs.'],
        'Q242' => ['no outstanding long-term debt', 'outstanding long-term debt', 'Agree and follow a repayment plan for the outstanding debt.'],
        'Q244' => ['debt with little exposure to rising interest rates', 'debt exposed to rising interest rates', 'Limit exposure to rising interest rates, for example by renegotiating or fixing the terms of the debt.'],

        // Governance
        'Q320' => ['a board recruited for the skills its strategy needs', 'a board not recruited for the skills its strategy needs', 'Recruit and elect board members against a skills matrix drawn from the strategy.'],
        'Q322' => ['board terms of under five years', 'board terms of five years or more', 'Shorten board terms to under five years, so the Board renews itself.'],
        'Q323' => ['term limits for board members', 'no term limits for board members', 'Introduce term limits for board members in the constitution.'],
        'Q325' => ['a term limit for the President or Chair', 'no term limit for the President or Chair', 'Introduce a term limit for the President or Chair.'],
        'Q325#2' => ['an annual self-evaluation of the Board', 'no annual self-evaluation of the Board', 'Hold an annual board self-evaluation and act on its findings.'],
        'Q326#2' => ['regular board meetings', 'too few board meetings in the past year', 'Hold at least two board meetings a year, with minutes kept.'],
        'Q327' => ['an annual evaluation of the General Secretary by the Board', 'no annual evaluation of the General Secretary by the Board', 'Have the Board evaluate the General Secretary’s performance every year.'],
        'Q328' => ['separate people as General Secretary and as Chair', 'the same person as General Secretary and as Chair', 'Separate the roles of General Secretary and Chair.'],
        'Q330' => ['a temporary succession plan for emergencies', 'no temporary succession plan for emergencies', 'Agree a temporary succession plan that names who leads in an emergency.'],
        'Q331' => ['a long-term succession plan', 'no long-term succession plan', 'Develop a long-term succession plan for the General Secretary and the Chair.'],
        'Q332' => ['succession planning that covers its key properties and assets', 'no succession planning for its key properties and assets', 'Include key properties and assets in succession planning.'],
        'Q333' => ['succession planning that covers management risks and liabilities', 'no succession planning for management risks and liabilities', 'Include management risks and liabilities in succession planning.'],

        // Constitution, By-laws, Policies and Procedures
        'Q410' => ['a constitution that is up to date with national requirements', 'a constitution that is not up to date with national requirements', 'Update the constitution to meet current national requirements.'],
        'Q412' => ['a conflict of interest policy', 'no conflict of interest policy', 'Adopt a conflict of interest policy, with an annual declaration by board members and senior staff.'],
        'Q413' => ['a safeguarding policy', 'no safeguarding policy', 'Adopt a safeguarding policy, including child protection, and train all staff and volunteers on it.'],
        'Q414' => ['a whistleblowing policy or grievance mechanism', 'no whistleblowing policy or grievance mechanism', 'Adopt a whistleblowing policy and a safe way to raise concerns.'],
        'Q415' => ['a diversity, equity and inclusion policy', 'no diversity, equity and inclusion policy', 'Adopt a diversity, equity and inclusion policy, including anti-discrimination.'],
        'Q416' => ['a health and safety policy', 'no health and safety policy', 'Adopt a health and safety policy for staff, volunteers and participants.'],
        'Q417' => ['a personnel (HR) policy', 'no personnel (HR) policy', 'Adopt a personnel (HR) policy covering recruitment, contracts and conduct.'],
        'Q418' => ['a financial procedures manual', 'no financial procedures manual', 'Write a financial procedures manual and train finance staff on it.'],
        'Q419' => ['a gift acceptance policy', 'no gift acceptance policy', 'Adopt a gift acceptance policy setting out which gifts the YMCA accepts.'],
        'Q420' => ['a cybersecurity and artificial intelligence policy', 'no cybersecurity or artificial intelligence policy', 'Adopt a cybersecurity and artificial intelligence policy.'],
        'Q421' => ['a data protection and privacy policy', 'no data protection and privacy policy', 'Adopt a data protection and privacy policy in line with national law.'],
        'Q422' => ['a procurement policy', 'no procurement policy', 'Adopt a procurement policy, with quotations and approval limits.'],
        'Q423' => ['a board nomination and election procedure', 'no board nomination and election procedure', 'Adopt a board nomination and election procedure.'],
        'Q424' => ['membership criteria for its member associations', 'no membership criteria for its member associations', 'Set membership criteria that every member association must meet.'],
        'Q425' => ['a code of conduct for board members', 'no code of conduct for board members', 'Adopt a code of conduct for board members.'],
        'Q426' => ['a code of conduct for staff and volunteers', 'no code of conduct for staff and volunteers', 'Adopt a code of conduct for staff and volunteers.'],
        'Q427' => ['policies for staff and volunteer wellbeing and development', 'no policy for staff and volunteer wellbeing and development', 'Adopt a policy for staff and volunteer wellbeing and professional development.'],
        'Q428' => ['a climate sustainability policy', 'no climate sustainability policy', 'Adopt a climate sustainability policy, for example on reducing the YMCA’s carbon footprint.'],
        'Q429' => ['an anti-corruption policy', 'no anti-corruption policy', 'Adopt an anti-corruption policy.'],
        'Q430' => ['a risk management policy or plan', 'no risk management policy or plan', 'Adopt a risk management plan with a risk register reviewed by the Board.'],

        // Monitoring & Evaluation
        'Q511' => ['project monitoring when funders require it', 'no project monitoring, even when funders require it', 'Monitor every funded project against its agreed indicators.'],
        'Q512' => ['organisation-wide monitoring', 'no organisation-wide monitoring', 'Set up organisation-wide monitoring, with common indicators across all programmes.'],
        'Q513' => ['project evaluations', 'no project evaluations', 'Evaluate each project at its end, and record what worked.'],
        'Q514' => ['organisation-wide evaluation', 'no organisation-wide evaluation', 'Evaluate programmes across the organisation each year, and feed the lessons into planning.'],
        'Q515' => ['a system to record knowledge and share it across the organisation', 'no system to record knowledge and share it across the organisation', 'Create a simple way to record lessons learned and share them across the movement.'],
        'Q516' => ['impact measurement tools', 'no impact measurement tools', 'Adopt impact measurement tools to show the difference the YMCA makes.'],

        // Strategic Planning
        'Q610' => ['a strategic plan', 'no current strategic plan', 'Develop a strategic plan aligned with Vision 2030, together with the Board, staff, members and young people.'],
        'Q612' => ['a strategic plan shaped with its staff', 'a strategic plan not shaped with its staff', 'Involve staff in developing and reviewing the strategic plan.'],
        'Q613' => ['a strategic plan shaped with its board members', 'a strategic plan not shaped with its board members', 'Involve board members in developing and reviewing the strategic plan.'],
        'Q614' => ['a strategic plan shaped with its members', 'a strategic plan not shaped with its members', 'Involve members in developing and reviewing the strategic plan.'],
        'Q615' => ['a strategic plan shaped with its volunteers', 'a strategic plan not shaped with its volunteers', 'Involve volunteers in developing and reviewing the strategic plan.'],
        'Q616' => ['a strategic plan shaped with young people and the communities it serves', 'a strategic plan not shaped with young people and the communities it serves', 'Involve young people and the communities served in developing and reviewing the strategic plan.'],
        'Q618' => ['an annual review of its strategic plan', 'no annual review of its strategic plan', 'Review and update the strategic plan every year.'],
        'Q619' => ['an innovation component in its strategic plan', 'no innovation component in its strategic plan', 'Add an innovation component to the strategic plan, in programmes or in operations.'],
        'Q620' => ['Vision 2030 reflected in its strategic plan', 'a strategic plan that does not yet reflect Vision 2030', 'Align the strategic plan with Vision 2030 and its four pillars.'],
        'Q630' => ['a mission and values aligned with the Christian values of the YMCA', 'a mission and values not aligned with the Christian values of the YMCA', 'Review the mission and values to align them with the Christian values of the YMCA movement.'],

        // Diversity and Youth Participation
        'Q711' => ['assured representation of young people in decision-making', 'no assured representation of young people in decision-making', 'Set a minimum share of young people on the Board and its committees.'],
        'Q712' => ['assured gender representation in decision-making', 'no assured gender representation in decision-making', 'Set a gender balance target for the Board and its committees.'],
        'Q714' => ['procedures for the equal participation of all board members', 'no procedure for the equal participation of all board members', 'Adopt procedures so every board member can take an equal part, whatever their background or experience.'],
        'Q716' => ['policies for the inclusion of young people, volunteers and staff', 'no policy for the inclusion of young people, volunteers and staff', 'Adopt inclusion policies for young people, volunteers and staff.'],

        // Communications and Branding
        'Q810' => ['a published annual report', 'no published annual report', 'Publish an annual report each year and make it available to the public.'],
        'Q811' => ['a website that is kept up to date', 'no regularly updated website', 'Keep the website up to date, with news, the annual report and contact details.'],
        'Q812' => ['a communications strategy', 'no communications strategy', 'Develop a communications strategy with clear audiences, messages and channels.'],
        'Q813' => ['a staff member or volunteer dedicated to communications', 'no one dedicated to communications', 'Give a staff member or volunteer responsibility for communications.'],
        'Q814' => ['a legally registered and protected brand', 'a name and logo that are not legally registered', 'Register the YMCA’s name and logo to protect the brand.'],

        // Staff and Volunteer Development
        'Q1001' => ['enough volunteers to deliver its operational plan', 'too few volunteers to deliver its operational plan', 'Recruit and keep more volunteers, with a volunteer management plan.'],
        'Q1002' => ['enough staff to deliver its operational plan', 'too few staff to deliver its operational plan', 'Plan staffing against the operational plan and fill the gaps.'],
        'Q1003' => ['an effective training system for staff and volunteers', 'no effective training system for staff and volunteers', 'Set up a regular training programme for staff and volunteers.'],
        'Q1004' => ['all its employees officially hired', 'employees who are not all officially hired', 'Give every employee an official contract, in line with labour law.'],
        'Q1007' => ['a job description for every position', 'positions without job descriptions', 'Write a job description for every position.'],
    ];
}
