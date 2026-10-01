<?php

namespace App\Support;

/**
 * Explicit markers of the local demo seeders (database/seeders/*Demo*).
 * Used to detect and remove demo records; never used to decide access.
 */
final class DemoData
{
    /** Domain of every demo account and of the non-production default Super Admin. */
    public const EMAIL_DOMAIN = '@salescrm.local';

    public const USER_EMAILS = [
        'admin@salescrm.local',
        'manager@salescrm.local',
        'rahul@salescrm.local',
        'priya@salescrm.local',
        'arjun@salescrm.local',
    ];

    public const USER_NAMES = ['Anita Admin', 'Mehul Manager', 'Rahul Sharma', 'Priya Patel', 'Arjun Mehta'];

    public const TEAM_NAMES = ['Ahmedabad Team', 'Mumbai Team'];

    public const CAMPAIGN_NAMES = ['Diwali Home Loan Offer 2026', 'Website Enquiry Form', 'Local Test Campaign'];

    /** Lead names created by the demo seeders ("First Last"). */
    public const LEAD_NAMES = [
        'Aditya Joshi', 'Amit Desai', 'Anjali Rao', 'Divya Menon', 'Farhan Qureshi', 'Harsh Trivedi',
        'Heena Parmar', 'Isha Kapoor', 'Jay Rathod', 'Karan Thakkar', 'Kavita Joshi', 'Kunal Shetty',
        'Manish Solanki', 'Neha Shah', 'Nikhil Chauhan', 'Pallavi Sawant', 'Pooja Mehta', 'Rakesh Patel',
        'Ritu Agarwal', 'Rohan Kulkarni', 'Sneha Iyer', 'Suresh Nair', 'Swati Deshmukh', 'Vikram Singh',
    ];
}
