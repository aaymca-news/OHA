<?php

/**
 * SAMPLE people for local development and demos only. Never seeded in production.
 *
 * Generated once from the Stage 1 prototype (app/js/data/users.js). The Secretariat
 * names match the prototype walkthrough; the Board Chairpersons are invented placeholders.
 * AAYMCA: one Super Administrator, two Administrators (the former O.H.A Leads) and
 * Staff. Each movement has two users: its Board Chairperson and its National
 * General Secretary.
 */

return [
    'secretariat' => [
        ['name' => 'Achieng Odhiambo', 'role' => 'super_admin', 'title' => 'Systems Administrator', 'movements' => []],
        ['name' => 'Gloria Anyika', 'role' => 'admin', 'title' => 'Head of Movement Strengthening', 'movements' => ['sierra-leone', 'liberia', 'ghana', 'togo', 'benin']],
        ['name' => 'Yirga Tesfaye', 'role' => 'admin', 'title' => 'Head of Programmes', 'movements' => ['ethiopia', 'south-sudan', 'kenya', 'burundi', 'tanzania']],
        ['name' => 'Samuel Mwangi', 'role' => 'staff', 'title' => 'Zonal Coordinator, East Africa', 'movements' => ['ethiopia', 'south-sudan', 'kenya', 'burundi', 'tanzania']],
        ['name' => 'Aminata Diallo', 'role' => 'staff', 'title' => 'Zonal Coordinator, West Africa', 'movements' => ['sierra-leone', 'liberia', 'ghana', 'togo', 'nigeria', 'senegal', 'gambia', 'benin', 'niger', 'guinea-bissau', 'guinea-conakry']],
        ['name' => 'Tendai Moyo', 'role' => 'staff', 'title' => 'Zonal Coordinator, Southern Africa', 'movements' => ['zambia', 'zimbabwe', 'south-africa', 'madagascar', 'namibia', 'malawi', 'cameroon']],
    ],
    'chairs' => [
        'sierra-leone' => ['name' => 'Fatou Sesay', 'title' => 'Board Chairperson'],
        'madagascar' => ['name' => 'Thandiwe Banda', 'title' => 'Board Chairperson'],
        'ethiopia' => ['name' => 'Wanjiru Kamau', 'title' => 'Board Chairperson'],
        'zambia' => ['name' => 'Naledi Moyo', 'title' => 'Board Chairperson'],
        'togo' => ['name' => 'Aminata Camara', 'title' => 'Board Chairperson'],
        'ghana' => ['name' => 'Awa Ndiaye', 'title' => 'Board Chairperson'],
        'liberia' => ['name' => 'Binta Jallow', 'title' => 'Board Chairperson'],
        'zimbabwe' => ['name' => 'Bongani Sithole', 'title' => 'Board Chairperson'],
        'south-africa' => ['name' => 'Tafadzwa Chirwa', 'title' => 'Board Chairperson'],
        'cameroon' => ['name' => 'Marie Ngo Bassong', 'title' => 'Board Chairperson'],
        'south-sudan' => ['name' => 'Esther Wambui', 'title' => 'Board Chairperson'],
        'nigeria' => ['name' => 'Salimatu Kamara', 'title' => 'Board Chairperson'],
        'senegal' => ['name' => 'Mariatu Bangura', 'title' => 'Board Chairperson'],
        'namibia' => ['name' => 'Chanda Mwale', 'title' => 'Board Chairperson'],
        'burundi' => ['name' => 'Rehema Juma', 'title' => 'Board Chairperson'],
        'kenya' => ['name' => 'Fatuma Hassan', 'title' => 'Board Chairperson'],
        'benin' => ['name' => 'Rokia Keita', 'title' => 'Board Chairperson'],
        'niger' => ['name' => 'Kwame Nyarko', 'title' => 'Board Chairperson'],
        'tanzania' => ['name' => 'Halima Said', 'title' => 'Board Chairperson'],
        'gambia' => ['name' => 'Sokhna Mbaye', 'title' => 'Board Chairperson'],
        'malawi' => ['name' => 'Mutinta Hamusonde', 'title' => 'Board Chairperson'],
        'guinea-bissau' => ['name' => 'Haja Koroma', 'title' => 'Board Chairperson'],
        'guinea-conakry' => ['name' => 'Djeneba Coulibaly', 'title' => 'Board Chairperson'],
    ],
    // Invented placeholders: each movement's National General Secretary (30 Oct 2026).
    'general_secretaries' => [
        'sierra-leone' => ['name' => 'Mohamed Kanu', 'title' => 'National General Secretary'],
        'madagascar' => ['name' => 'Hery Rakoto', 'title' => 'National General Secretary'],
        'ethiopia' => ['name' => 'Dawit Bekele', 'title' => 'National General Secretary'],
        'zambia' => ['name' => 'Mwila Banda', 'title' => 'National General Secretary'],
        'togo' => ['name' => 'Kossi Agbeko', 'title' => 'National General Secretary'],
        'ghana' => ['name' => 'Kofi Mensah', 'title' => 'National General Secretary'],
        'liberia' => ['name' => 'Joseph Kollie', 'title' => 'National General Secretary'],
        'zimbabwe' => ['name' => 'Tatenda Ncube', 'title' => 'National General Secretary'],
        'south-africa' => ['name' => 'Sipho Dlamini', 'title' => 'National General Secretary'],
        'cameroon' => ['name' => 'Jean-Pierre Mbarga', 'title' => 'National General Secretary'],
        'south-sudan' => ['name' => 'Deng Garang', 'title' => 'National General Secretary'],
        'nigeria' => ['name' => 'Chinedu Okafor', 'title' => 'National General Secretary'],
        'senegal' => ['name' => 'Ousmane Sow', 'title' => 'National General Secretary'],
        'namibia' => ['name' => 'Tuhafeni Shilongo', 'title' => 'National General Secretary'],
        'burundi' => ['name' => 'Eric Ndayishimiye', 'title' => 'National General Secretary'],
        'kenya' => ['name' => 'Brian Otieno', 'title' => 'National General Secretary'],
        'benin' => ['name' => 'Codjo Houngbo', 'title' => 'National General Secretary'],
        'niger' => ['name' => 'Issoufou Maiga', 'title' => 'National General Secretary'],
        'tanzania' => ['name' => 'Baraka Mushi', 'title' => 'National General Secretary'],
        'gambia' => ['name' => 'Lamin Ceesay', 'title' => 'National General Secretary'],
        'malawi' => ['name' => 'Chikondi Phiri', 'title' => 'National General Secretary'],
        'guinea-bissau' => ['name' => 'Braima Embalo', 'title' => 'National General Secretary'],
        'guinea-conakry' => ['name' => 'Mamadou Bah', 'title' => 'National General Secretary'],
    ],
];
